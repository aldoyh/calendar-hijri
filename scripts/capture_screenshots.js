const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const PORT = 9224;
const APP_URL = 'http://127.0.0.1:8011';
const OUT_DIR = path.join(__dirname, '../docs/screenshots');

fs.mkdirSync(OUT_DIR, { recursive: true });

async function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function sendCdp(ws, method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = Math.floor(Math.random() * 1000000);
        const handler = (event) => {
            const data = JSON.parse(event.data);
            if (data.id === id) {
                ws.removeEventListener('message', handler);
                if (data.error) reject(data.error);
                else resolve(data.result);
            }
        };
        ws.addEventListener('message', handler);
        ws.send(JSON.stringify({ id, method, params }));
    });
}

async function captureFullPage(ws, filename) {
    // Get full document content size
    const metrics = await sendCdp(ws, 'Page.getLayoutMetrics');
    const width = 1440;
    const height = Math.max(960, Math.ceil(metrics.contentSize ? metrics.contentSize.height : 1200));

    await sendCdp(ws, 'Emulation.setDeviceMetricsOverride', {
        width,
        height,
        deviceScaleFactor: 2,
        mobile: false
    });

    await sleep(400);

    const shot = await sendCdp(ws, 'Page.captureScreenshot', {
        format: 'png',
        captureBeyondViewport: true
    });

    const buf = Buffer.from(shot.data, 'base64');
    const dest = path.join(OUT_DIR, filename);
    fs.writeFileSync(dest, buf);
    console.log(`Saved screenshot ${filename}: ${dest} (${buf.length} bytes, ${width}x${height}@2x)`);
}

async function main() {
    console.log('Spawning Chrome headless on port ' + PORT + '...');
    const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
        '--headless=new',
        `--remote-debugging-port=${PORT}`,
        '--hide-scrollbars',
        '--window-size=1440,1200',
        '--no-first-run',
        '--no-default-browser-check'
    ], { stdio: 'ignore' });

    let connected = false;
    let pageInfo = null;

    for (let i = 0; i < 20; i++) {
        await sleep(300);
        try {
            const res = await fetch(`http://127.0.0.1:${PORT}/json/new?${APP_URL}`, { method: 'PUT' });
            pageInfo = await res.json();
            if (pageInfo && pageInfo.webSocketDebuggerUrl) {
                connected = true;
                break;
            }
        } catch (e) {}
    }

    if (!connected || !pageInfo) {
        console.error('Failed to connect to Chrome CDP');
        chrome.kill();
        process.exit(1);
    }

    console.log('Connecting WebSocket to page...');
    const ws = new WebSocket(pageInfo.webSocketDebuggerUrl);

    await new Promise((resolve, reject) => {
        ws.onopen = resolve;
        ws.onerror = reject;
    });

    await sendCdp(ws, 'Page.enable');
    await sendCdp(ws, 'Runtime.enable');
    await sendCdp(ws, 'DOM.enable');

    console.log('Waiting for initial load and GSAP entrance animations...');
    await sleep(3500);

    // 1. Dashboard full page screenshot
    console.log('Capturing Dashboard full page...');
    await sendCdp(ws, 'Runtime.evaluate', {
        expression: `
            (() => {
                const el = document.querySelector('[x-data]');
                if (el && window.Alpine) {
                    window.Alpine.$data(el).changePage('dashboard');
                }
            })()
        `
    });
    await sleep(600);
    await captureFullPage(ws, 'dashboard.png');

    // Also copy to root screenshot.png
    fs.copyFileSync(path.join(OUT_DIR, 'dashboard.png'), path.join(__dirname, '../screenshot.png'));

    // 2. Day Modal interactive screenshot (click day 11)
    console.log('Opening Day Modal for day 11...');
    await sendCdp(ws, 'Runtime.evaluate', {
        expression: `
            (() => {
                const el = document.querySelector('[x-data]');
                if (el && window.Alpine) {
                    window.Alpine.$data(el).openModal(11);
                }
            })()
        `
    });
    await sleep(800);
    await captureFullPage(ws, 'modal_interactive.png');

    // Close modal
    await sendCdp(ws, 'Runtime.evaluate', {
        expression: `
            (() => {
                const el = document.querySelector('[x-data]');
                if (el && window.Alpine) {
                    window.Alpine.$data(el).closeModal();
                }
            })()
        `
    });
    await sleep(500);

    // 3. Other Pages
    const pages = [
        { name: 'finance.png', pageId: 'finance' },
        { name: 'goals.png', pageId: 'goals' },
        { name: 'journal.png', pageId: 'journal' },
        { name: 'habits.png', pageId: 'habits' },
        { name: 'vault.png', pageId: 'vault' }
    ];

    for (const p of pages) {
        console.log(`Navigating to ${p.pageId}...`);
        await sendCdp(ws, 'Runtime.evaluate', {
            expression: `
                (() => {
                    const el = document.querySelector('[x-data]');
                    if (el && window.Alpine) {
                        window.Alpine.$data(el).changePage('${p.pageId}');
                    }
                })()
            `
        });
        await sleep(800);
        await captureFullPage(ws, p.name);
    }

    // 4. English Mode full page screenshot
    console.log('Switching to English Mode...');
    await sendCdp(ws, 'Runtime.evaluate', {
        expression: `
            (() => {
                const el = document.querySelector('[x-data]');
                if (el && window.Alpine) {
                    const d = window.Alpine.$data(el);
                    if (d.lang !== 'en') {
                        d.toggleLanguage();
                    }
                    d.changePage('dashboard');
                }
            })()
        `
    });
    await sleep(1000);
    await captureFullPage(ws, 'english_preview.png');

    // Revert back to Arabic for persistent setting
    await sendCdp(ws, 'Runtime.evaluate', {
        expression: `
            (() => {
                const el = document.querySelector('[x-data]');
                if (el && window.Alpine) {
                    const d = window.Alpine.$data(el);
                    if (d.lang !== 'ar') {
                        d.toggleLanguage();
                    }
                }
            })()
        `
    });
    await sleep(500);

    ws.close();
    chrome.kill();
    console.log('All full-page Retina screenshots captured successfully!');
}

main().catch(err => {
    console.error('Fatal error:', err);
    process.exit(1);
});
