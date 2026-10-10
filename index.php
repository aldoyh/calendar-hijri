<?php
/**
 * Calendarify Pro 2026 - Ultra Lithic Edition (v3.5)
 * Architecture: PHP 8.5+ | SQLite3 | Alpine.js 3.14+ | GSAP 3.12+ | Tailwind CSS
 * Fixes & Additions: Top-mounted Media Controls, Hijri/Gregorian Dual Dates, Z-Index corrections.
 */
declare(strict_types=1);

$dbPath = __DIR__ . '/calendarify.sqlite';
$pdo = new PDO("sqlite:{$dbPath}", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, date TEXT NOT NULL, content TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, completed INTEGER DEFAULT 0, due_time TEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS birthdays (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, date TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE IF NOT EXISTS quotes (id INTEGER PRIMARY KEY AUTOINCREMENT, text TEXT NOT NULL UNIQUE, is_displayed INTEGER DEFAULT 0);
");

// Migration check for quotes table
$quoteCols = $pdo->query("PRAGMA table_info(quotes)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('is_displayed', $quoteCols, true)) {
    $pdo->exec("ALTER TABLE quotes ADD COLUMN is_displayed INTEGER DEFAULT 0");
}

$defaultSettings = [
    'header_img' => 'https://images.unsplash.com/photo-1518895949257-7621c3c786d7?q=80&w=2000',
    'header_pos_y' => '42',
    'theme_mode' => 'dark',
    'lang' => 'ar',
    'avatar_img' => 'https://api.dicebear.com/7.x/avataaars/svg?seed=Shaikha2026'
];
foreach ($defaultSettings as $key => $val) {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $val]);
}

/**
 * Image Resizer & Optimizer
 * - Header Cover (header_img): Landscape banner, max 1920x1080 (proportional aspect ratio preserved)
 * - Avatar (avatar_img): Square 1:1, 400x400 (center-cropped)
 */
function resizeImageFile(string $filePath, string $target): bool {
    if (!file_exists($filePath)) return false;
    $info = @getimagesize($filePath);
    if (!$info) return false;
    [$origW, $origH] = $info;
    $mime = $info['mime'];

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($filePath),
        'image/png'  => @imagecreatefrompng($filePath),
        'image/webp' => @imagecreatefromwebp($filePath),
        'image/gif'  => @imagecreatefromgif($filePath),
        default      => null
    };
    if (!$src) return false;

    if ($target === 'avatar_img') {
        // Avatar requirement: Square 1:1, 400x400 center-cropped
        $targetW = 400;
        $targetH = 400;
        $cropSize = min($origW, $origH);
        $cropX = (int)floor(($origW - $cropSize) / 2);
        $cropY = (int)floor(($origH - $cropSize) / 2);

        $dst = imagecreatetruecolor($targetW, $targetH);
        if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropSize, $cropSize);
    } else {
        // Header cover requirement: Landscape hero banner, max 1920x1080 maintaining aspect ratio
        $maxW = 1920;
        $maxH = 1080;
        if ($origW <= $maxW && $origH <= $maxH) {
            $dstW = $origW;
            $dstH = $origH;
        } else {
            $ratio = min($maxW / $origW, $maxH / $origH);
            $dstW = (int)round($origW * $ratio);
            $dstH = (int)round($origH * $ratio);
        }
        $dst = imagecreatetruecolor($dstW, $dstH);
        if (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $origW, $origH);
    }

    return match ($mime) {
        'image/jpeg' => imagejpeg($dst, $filePath, 85),
        'image/png'  => imagepng($dst, $filePath, 8),
        'image/webp' => imagewebp($dst, $filePath, 85),
        'image/gif'  => imagegif($dst, $filePath),
        default      => false
    };
}

/**
 * Seed quotes from production json cache or randomized HuggingFace dataset
 */
function seedQuotes(PDO $pdo): void {
    $seedFile = __DIR__ . '/quotes_seed.json';
    $quotes = [];

    // 1. Prefer shipping pre-seeded production dataset for 0-latency offline production launch
    if (file_exists($seedFile)) {
        $json = @file_get_contents($seedFile);
        if ($json) {
            $quotes = json_decode($json, true) ?? [];
        }
    }

    // 2. Fallback to randomized fetch from HuggingFace dataset across dynamic bucket offsets
    if (count($quotes) < 100) {
        $totalRows = 5964;
        $batchSize = 100;
        $needed = 500;
        $maxOffset = max(0, $totalRows - $batchSize);
        $numBatches = 6;
        $bucketSize = (int)floor($maxOffset / $numBatches);
        $offsets = [];
        for ($i = 0; $i < $numBatches; $i++) {
            $min = $i * $bucketSize;
            $max = min($maxOffset, ($i + 1) * $bucketSize - $batchSize);
            $offsets[] = rand($min, max($min, $max));
        }
        shuffle($offsets);

        $collected = [];
        foreach ($offsets as $offset) {
            if (count($collected) >= $needed) break;
            $url = "https://datasets-server.huggingface.co/rows?dataset=AhmedBou%2FArabic_Quotes&config=default&split=train&offset={$offset}&length={$batchSize}";
            $ctx = stream_context_create(["http" => ["timeout" => 10, "header" => "User-Agent: Calendarify/1.0\r\n"]]);
            $res = @file_get_contents($url, false, $ctx);
            if (!$res) continue;
            $data = json_decode($res, true);
            if (empty($data["rows"])) continue;
            foreach ($data["rows"] as $r) {
                $q = trim($r["row"]["quote"] ?? "");
                if (mb_strlen($q) > 5) {
                    $collected[$q] = true;
                }
            }
        }
        $quotes = array_slice(array_keys($collected), 0, $needed);
        shuffle($quotes);
        if (!empty($quotes)) {
            @file_put_contents($seedFile, json_encode($quotes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    if (!empty($quotes)) {
        $ins = $pdo->prepare("INSERT OR IGNORE INTO quotes (text, is_displayed) VALUES (?, 0)");
        foreach ($quotes as $q) {
            $ins->execute([$q]);
        }
    }
}

/**
 * Fetch an undisplayed random quote, mark it as displayed,
 * and automatically reset all quotes when the pool is completely depleted.
 */
function getNextQuote(PDO $pdo): string {
    $stmt = $pdo->prepare("SELECT id, text FROM quotes WHERE is_displayed = 0 ORDER BY RANDOM() LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        // Pool is depleted: reset all quotes back to undisplayed
        $pdo->exec("UPDATE quotes SET is_displayed = 0");
        $stmt->execute();
        $row = $stmt->fetch();
    }

    if ($row) {
        $update = $pdo->prepare("UPDATE quotes SET is_displayed = 1 WHERE id = ?");
        $update->execute([$row['id']]);
        return $row['text'];
    }

    return "التخطيط نصف النجاح";
}

if ((int)$pdo->query("SELECT COUNT(*) FROM quotes")->fetchColumn() === 0) {
    seedQuotes($pdo);
}

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    // File/URL Upload Handler with proper resizing
    if ($_GET['api'] === 'update_media') {
        try {
            $target = $_POST['target'] ?? '';
            if (!in_array($target, ['header_img', 'avatar_img'], true)) throw new Exception('Invalid target');
            
            $finalUrl = '';
            if (!empty($_POST['url'])) {
                $finalUrl = trim($_POST['url']);
            } elseif (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) throw new Exception('Invalid image format');
                if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0775, true);
                $filename = $target . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $destPath = __DIR__ . '/uploads/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    throw new Exception('Failed to save uploaded file');
                }
                // Properly resize according to target requirements
                resizeImageFile($destPath, $target);
                $finalUrl = './uploads/' . $filename;
            } else {
                throw new Exception('No media provided');
            }
            
            $pdo->prepare("INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v")
                ->execute([':k' => $target, ':v' => $finalUrl]);
            echo json_encode(['ok' => true, 'path' => $finalUrl]);
        } catch (Throwable $e) {
            http_response_code(400); echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        switch ($_GET['api']) {
            case 'save_setting':
                $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v");
                $stmt->execute([':k' => $payload['key'], ':v' => (string)$payload['value']]);
                echo json_encode(['ok' => true]); break;
            case 'add_reminder':
                $pdo->prepare("INSERT INTO reminders (date, content) VALUES (?, ?)")->execute([$payload['date'], trim($payload['content'])]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); break;
            case 'delete_reminder':
                $pdo->prepare("DELETE FROM reminders WHERE id = ?")->execute([(int)$payload['id']]);
                echo json_encode(['ok' => true]); break;
            case 'add_task':
                $pdo->prepare("INSERT INTO tasks (title, due_time) VALUES (?, ?)")->execute([trim($payload['title']), $payload['due_time'] ?? null]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); break;
            case 'toggle_task':
                $pdo->prepare("UPDATE tasks SET completed = CASE WHEN completed = 1 THEN 0 ELSE 1 END WHERE id = ?")->execute([(int)$payload['id']]);
                echo json_encode(['ok' => true]); break;
            case 'delete_task':
                $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([(int)$payload['id']]);
                echo json_encode(['ok' => true]); break;
            case 'add_birthday':
                $pdo->prepare("INSERT INTO birthdays (name, date) VALUES (?, ?)")->execute([trim($payload['name']), $payload['date']]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); break;
            case 'delete_birthday':
                $pdo->prepare("DELETE FROM birthdays WHERE id = ?")->execute([(int)$payload['id']]);
                echo json_encode(['ok' => true]); break;
            case 'get_quote':
                echo json_encode(['ok' => true, 'quote' => getNextQuote($pdo)]); break;
            default:
                http_response_code(404); echo json_encode(['error' => 'Not found']);
        }
    } catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

$settings = $pdo->query("SELECT key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$reminders = $pdo->query("SELECT id, date, content FROM reminders ORDER BY id ASC")->fetchAll();
$tasks = $pdo->query("SELECT id, title, completed, due_time FROM tasks ORDER BY id DESC")->fetchAll();
$birthdays = $pdo->query("SELECT id, name, date FROM birthdays ORDER BY date ASC")->fetchAll();
$initialQuote = getNextQuote($pdo);
$activeTheme = $settings['theme_mode'] ?? 'dark';
$activeLang = $settings['lang'] ?? 'ar';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($activeLang) ?>" dir="<?= $activeLang === 'ar' ? 'rtl' : 'ltr' ?>" class="<?= $activeTheme === 'dark' ? 'dark' : '' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>2026 Planner | Ultra Lithic Studio</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/Draggable.min.js"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { tajawal: ['Tajawal', 'sans-serif'], mono: ['JetBrains Mono', 'monospace'] },
                    colors: {
                        background: "hsl(var(--background))", foreground: "hsl(var(--foreground))",
                        card: "hsl(var(--card))", "card-foreground": "hsl(var(--card-foreground))",
                        popover: "hsl(var(--popover))", "popover-foreground": "hsl(var(--popover-foreground))",
                        primary: "hsl(var(--primary))", "primary-foreground": "hsl(var(--primary-foreground))",
                        secondary: "hsl(var(--secondary))", "secondary-foreground": "hsl(var(--secondary-foreground))",
                        muted: "hsl(var(--muted))", "muted-foreground": "hsl(var(--muted-foreground))",
                        accent: "hsl(var(--accent))", "accent-foreground": "hsl(var(--accent-foreground))",
                        border: "hsl(var(--border))", input: "hsl(var(--input))", ring: "hsl(var(--ring))",
                    }
                }
            }
        }
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@500;700;800&family=Tajawal:wght@300;400;500;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --background: 220 14% 96%; --foreground: 224 71.4% 4.1%;
            --card: 0 0% 100%; --card-foreground: 224 71.4% 4.1%;
            --popover: 0 0% 100%; --popover-foreground: 224 71.4% 4.1%;
            --primary: 262.1 83.3% 57.8%; --primary-foreground: 210 20% 98%;
            --secondary: 220 14.3% 95.9%; --secondary-foreground: 220.9 39.3% 11%;
            --muted: 220 14.3% 95.9%; --muted-foreground: 220 8.9% 46.1%;
            --accent: 220 14.3% 95.9%; --accent-foreground: 220.9 39.3% 11%;
            --border: 220 13% 91%; --input: 220 13% 91%; --ring: 262.1 83.3% 57.8%;
            --radius: 1rem; --flap-bg: 224 71.4% 4.1%; --flap-fg: 0 0% 100%;
        }
        .dark {
            --background: 224 71.4% 4.1%; --foreground: 210 20% 98%;
            --card: 222.2 84% 4.9%; --card-foreground: 210 20% 98%;
            --popover: 222.2 84% 4.9%; --popover-foreground: 210 20% 98%;
            --primary: 263.4 70% 50.4%; --primary-foreground: 210 20% 98%;
            --secondary: 217.2 32.6% 17.5%; --secondary-foreground: 210 20% 98%;
            --muted: 217.2 32.6% 17.5%; --muted-foreground: 215 20.2% 65.1%;
            --accent: 217.2 32.6% 17.5%; --accent-foreground: 210 20% 98%;
            --border: 217.2 32.6% 17.5%; --input: 217.2 32.6% 17.5%; --ring: 263.4 70% 50.4%;
            --flap-bg: 222.2 84% 4.9%; --flap-fg: 210 20% 98%;
        }
        
        * { font-family: 'Tajawal', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        [x-cloak] { display: none !important; }
        
        body { overflow-x: hidden; scroll-behavior: smooth; }
        .glass-panel { background: rgba(var(--card), 0.7); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: hsl(var(--border)); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: hsl(var(--muted-foreground)); }

        .flap-container { perspective: 1000px; transform-style: preserve-3d; direction: ltr !important; }
        .flap-card {
            position: relative; width: 60px; height: 72px; background: hsl(var(--flap-bg)); 
            border-radius: 12px; border: 1px solid hsl(var(--border));
            box-shadow: inset 0 2px 4px rgba(255,255,255,0.05), 0 4px 12px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .flap-card::before {
            content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 2px;
            background: rgba(0,0,0,0.85); z-index: 1; transform: translateY(-50%);
            box-shadow: 0 1px 1px rgba(255,255,255,0.1);
        }
        .flap-digit {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem; font-weight: 800; color: hsl(var(--flap-fg)); 
            text-shadow: 0 2px 4px rgba(0,0,0,0.5); z-index: 10;
        }
        
        .task-checkbox:checked + div { text-decoration: line-through; opacity: 0.5; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
    </style>
</head>
<body x-data="calendarifyApp()" class="bg-background text-foreground min-h-screen antialiased selection:bg-primary/20 selection:text-primary">

    <!-- Toast Notifications -->
    <div class="fixed bottom-6 right-6 z-[70] flex flex-col gap-2" id="toast-container">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="toast-message flex items-center gap-3 px-4 py-3 rounded-2xl bg-card border border-border shadow-2xl overflow-hidden glass-panel"
                 x-init="animateToast($el, toast.id)">
                <div class="w-2 h-2 rounded-full" :class="toast.type === 'success' ? 'bg-green-500' : 'bg-red-500'"></div>
                <p class="text-xs font-bold text-foreground" x-text="toast.message"></p>
            </div>
        </template>
    </div>

    <!-- Minimalist Sticky Header -->
    <header class="gsap-navbar border-b border-border glass-panel sticky top-0 z-40 px-6 py-3 flex justify-between items-center text-xs opacity-0">
        <div class="flex items-center gap-3 font-semibold">
            <div class="relative flex h-3 w-3">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
              <span class="relative inline-flex rounded-full h-3 w-3 bg-primary"></span>
            </div>
            <span class="tracking-tight font-bold text-sm" x-text="i18n[lang].appTitle"></span>
            <span class="text-muted-foreground/40 font-light">|</span>
            <span class="text-muted-foreground font-mono font-medium" x-text="todayDateFormatted"></span>
        </div>
        <div class="flex items-center gap-2">
            <button @click="toggleLanguage()" class="px-3 py-1.5 rounded-xl border border-border bg-secondary/50 hover:bg-secondary text-foreground transition font-bold shadow-sm">
                <span x-text="lang === 'ar' ? 'EN' : 'AR'"></span>
            </button>
            <button @click="toggleTheme()" class="p-2 rounded-xl border border-border bg-secondary/50 hover:bg-secondary text-foreground transition shadow-sm">
                <span x-show="darkMode">☀️</span>
                <span x-show="!darkMode">🌙</span>
            </button>
        </div>
    </header>

    <!-- Interactive Hero Canvas -->
    <div class="relative w-full h-[35vh] overflow-hidden group bg-card border-b border-border cursor-ns-resize"
         @mousedown="startDrag($event)" @mousemove="onDrag($event)" @mouseup="stopDrag()" @mouseleave="stopDrag()">
        
        <img :src="headerImg" class="gsap-hero-img w-full h-full object-cover pointer-events-none select-none brightness-[0.85] dark:brightness-[0.65] contrast-[1.1] scale-105 opacity-0" :style="`object-position: center ${headerPosY}%;`" />
        <div class="absolute inset-0 bg-gradient-to-t from-background via-background/40 to-transparent pointer-events-none"></div>
        <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0IiBoZWlnaHQ9IjQiPgo8cmVjdCB3aWR0aD0iNCIgaGVpZ2h0PSI0IiBmaWxsPSIjZmZmIiBmaWxsLW9wYWNpdHk9IjAuMDUiLz4KPC9zdmc+')] opacity-50 pointer-events-none"></div>
        
        <!-- Controls moved to top-20 to avoid z-index overlap from the bottom main container -->
        <div class="absolute top-20 end-6 opacity-0 group-hover:opacity-100 transition-opacity duration-300 z-30 flex gap-2" @mousedown.stop>
            <div class="bg-card/80 backdrop-blur-md px-3 py-2 rounded-xl border border-border text-xs flex items-center gap-2 shadow-xl ring-1 ring-primary/20" x-show="isDragging">
                <span class="animate-pulse">↕️</span> <span class="font-mono text-primary font-bold" x-text="headerPosY + '%'"></span>
            </div>
            <button type="button" @click.stop="openMediaModal('header_img')" class="bg-card/90 hover:bg-card border border-border text-foreground text-xs px-4 py-2 rounded-xl transition shadow-xl flex items-center gap-2 font-medium hover:ring-1 ring-border">
                <span>📸</span> <span x-text="i18n[lang].changeCover"></span>
            </button>
        </div>
    </div>

    <!-- Main Grid Dashboard -->
    <main class="max-w-[1800px] mx-auto px-6 lg:px-10 -mt-24 relative z-20 pb-20">
        
        <!-- Top Profile & Clock Bar -->
        <div class="flex flex-col xl:flex-row items-start xl:items-end justify-between gap-6 mb-10 pointer-events-none">
            <div class="flex items-end gap-6 pointer-events-auto">
                
                <div @click="openMediaModal('avatar_img')" class="gsap-profile w-36 h-36 rounded-[2rem] border-4 border-background overflow-hidden shadow-2xl bg-card shrink-0 relative z-10 opacity-0 group cursor-pointer ring-1 ring-border">
                    <img :src="avatarImg" class="w-full h-full object-cover group-hover:scale-110 group-hover:blur-sm transition-all duration-500">
                    <div class="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                        <span class="text-white text-3xl drop-shadow-lg">📸</span>
                    </div>
                </div>
                
                <div class="space-y-3 gsap-title opacity-0 mb-2">
                    <h1 class="text-4xl lg:text-5xl font-black tracking-tight text-foreground" x-text="i18n[lang].greeting"></h1>
                    <div class="inline-flex items-center gap-3 bg-card/60 border border-border/50 px-5 py-3 rounded-2xl shadow-sm backdrop-blur-xl">
                        <span class="text-primary font-black text-xl leading-none">“</span>
                        <p class="text-sm text-muted-foreground font-medium italic" x-text="currentQuote"></p>
                        <button @click="refreshQuote()" class="text-muted-foreground/50 hover:text-primary transition hover:rotate-180 duration-500">🔄</button>
                    </div>
                </div>
            </div>

            <div class="gsap-clock bg-card/60 backdrop-blur-xl border border-border/50 rounded-[2rem] p-5 shadow-2xl flex items-center gap-5 opacity-0 mb-2 pointer-events-auto" dir="ltr" style="direction: ltr;">
                <div class="flap-container flex items-center gap-2" dir="ltr" style="direction: ltr;">
                    <div class="flap-card"><div class="flap-digit" x-text="time.h"></div></div>
                    <div class="text-2xl font-black text-muted-foreground/40 animate-pulse pb-1">:</div>
                    <div class="flap-card"><div class="flap-digit" x-text="time.m"></div></div>
                    <div class="text-2xl font-black text-muted-foreground/40 animate-pulse pb-1">:</div>
                    <div class="flap-card"><div class="flap-digit text-primary" x-text="time.s"></div></div>
                </div>
                <div class="border-s border-border/50 ps-5 text-start">
                    <p class="text-sm font-black uppercase tracking-widest text-primary mb-1" x-text="time.dayName"></p>
                    <p class="text-[10px] font-mono text-muted-foreground uppercase tracking-wider">Swiss Master Chrono</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-12 gap-6 lg:gap-8 items-start">
            
            <!-- LEFT COLUMN -->
            <aside class="col-span-12 lg:col-span-3 space-y-6">
                <nav class="gsap-panel bg-card border border-border rounded-[2rem] p-6 shadow-sm opacity-0">
                    <h2 class="text-xs font-bold text-muted-foreground uppercase tracking-widest mb-5 flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-primary/50"></span> <span x-text="i18n[lang].navigation"></span></h2>
                    <ul class="space-y-1">
                        <template x-for="(item, idx) in i18n[lang].navItems" :key="idx">
                            <li>
                                <a href="#" class="flex items-center gap-4 px-4 py-3 rounded-2xl hover:bg-secondary text-sm font-bold text-foreground/80 hover:text-foreground transition-all group border border-transparent hover:border-border/50">
                                    <span x-text="item.icon" class="text-lg group-hover:scale-125 group-hover:rotate-6 transition-transform duration-300"></span>
                                    <span x-text="item.title"></span>
                                </a>
                            </li>
                        </template>
                    </ul>
                </nav>

                <div class="gsap-panel bg-card border border-border rounded-[2rem] p-6 shadow-sm opacity-0 flex flex-col h-[400px]">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-xs font-bold text-muted-foreground uppercase tracking-widest flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-pink-500/50"></span> <span x-text="i18n[lang].birthdaysTitle"></span></h2>
                        <span class="text-[10px] font-mono bg-primary/10 text-primary px-2.5 py-1 rounded-full font-bold" x-text="birthdays.length"></span>
                    </div>

                    <div class="flex-1 overflow-y-auto space-y-2 pe-2 custom-scrollbar">
                        <template x-for="b in birthdays" :key="b.id">
                            <div class="birthday-item flex items-center justify-between p-3 rounded-2xl bg-secondary/50 border border-border/50 group hover:border-primary/30 transition-colors">
                                <div>
                                    <p class="text-xs font-bold text-foreground mb-0.5" x-text="b.name"></p>
                                    <p class="text-[10px] text-muted-foreground font-mono bg-background px-1.5 py-0.5 rounded-md inline-block border border-border/50" x-text="b.date"></p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-[10px] px-2 py-1 rounded-lg font-bold shadow-sm" :class="isBdayUpcoming(b.date) ? 'bg-primary/20 text-primary ring-1 ring-primary/30' : 'bg-background border border-border text-muted-foreground'" x-text="getBdayCount(b.date)"></span>
                                    <button @click="delBirthday(b.id)" class="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-red-500 transition px-1">✕</button>
                                </div>
                            </div>
                        </template>
                        <template x-if="birthdays.length === 0"><div class="text-xs text-muted-foreground/50 text-center py-4 italic" x-text="i18n[lang].noData"></div></template>
                    </div>

                    <form @submit.prevent="addBday()" class="mt-4 pt-4 border-t border-border/50 space-y-2">
                        <input type="text" x-model="newBday.name" :placeholder="i18n[lang].bdayNamePlaceholder" required class="w-full bg-secondary/50 border border-border rounded-xl px-4 py-2.5 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary/50 transition-shadow">
                        <div class="flex gap-2">
                            <input type="text" x-model="newBday.date" placeholder="MM-DD" pattern="^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$" required class="w-1/2 bg-secondary/50 border border-border rounded-xl px-4 py-2.5 text-xs font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-primary/50">
                            <button type="submit" class="w-1/2 bg-primary hover:bg-primary/90 text-primary-foreground rounded-xl text-xs font-bold py-2.5 transition shadow-lg shadow-primary/20">+</button>
                        </div>
                    </form>
                </div>
            </aside>

            <!-- CENTER COLUMN: Calendar Dual-Date Matrix -->
            <section class="gsap-panel col-span-12 lg:col-span-6 bg-card border border-border rounded-[2rem] p-8 shadow-sm opacity-0">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-3xl font-black text-foreground mb-1" x-text="monthNames[lang][currentMonth] + ' ' + currentYear"></h2>
                        <p class="text-xs font-bold tracking-widest text-primary uppercase" x-text="i18n[lang].calendarSubtitle"></p>
                    </div>
                    <div class="flex items-center gap-1 bg-secondary p-1.5 rounded-2xl border border-border shadow-inner">
                        <button @click="changeMonth(-1)" class="w-10 h-10 flex items-center justify-center hover:bg-background rounded-xl transition text-foreground font-bold shadow-sm">
                            <span x-text="lang === 'ar' ? '→' : '←'"></span>
                        </button>
                        <button @click="resetMonth()" class="px-4 h-10 text-xs font-bold hover:bg-background rounded-xl transition text-foreground shadow-sm" x-text="i18n[lang].today"></button>
                        <button @click="changeMonth(1)" class="w-10 h-10 flex items-center justify-center hover:bg-background rounded-xl transition text-foreground font-bold shadow-sm">
                            <span x-text="lang === 'ar' ? '←' : '→'"></span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-7 gap-3 text-center text-[10px] font-black text-muted-foreground uppercase tracking-widest mb-3">
                    <template x-for="day in dayNames[lang]" :key="day"><div x-text="day"></div></template>
                </div>

                <div class="grid grid-cols-7 gap-3">
                    <template x-for="blank in offset" :key="'blank-'+blank">
                        <div class="calendar-cell min-h-[100px] rounded-2xl bg-secondary/30 border border-dashed border-border/40"></div>
                    </template>
                    
                    <template x-for="day in daysInMonth" :key="day">
                        <div @click="openModal(day)"
                             class="calendar-cell min-h-[100px] rounded-2xl p-3 border transition-all duration-300 cursor-pointer flex flex-col justify-between group relative overflow-hidden"
                             :class="isToday(day) ? 'bg-primary/5 border-primary/50 shadow-lg shadow-primary/5 ring-1 ring-primary/20' : 'bg-background border-border hover:border-primary/40 hover:shadow-xl hover:-translate-y-1'">
                            
                            <div class="absolute inset-0 bg-gradient-to-br from-primary/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                            
                            <!-- Dual Dates Layout: Gregorian & Hijri -->
                            <div class="flex justify-between items-start relative z-10 w-full">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold w-7 h-7 rounded-full flex items-center justify-center font-mono transition-colors"
                                          :class="isToday(day) ? 'bg-primary text-primary-foreground shadow-md' : 'text-foreground bg-secondary group-hover:bg-primary/20 group-hover:text-primary'" x-text="day"></span>
                                    
                                    <span class="text-[10px] font-bold text-muted-foreground/60 font-mono" x-text="getHijriDay(day)"></span>
                                </div>
                                <template x-if="hasBday(day)"><span class="text-xs animate-bounce drop-shadow-md">🎂</span></template>
                            </div>

                            <div class="space-y-1.5 my-2 relative z-10">
                                <template x-for="r in getDayRems(day).slice(0, 2)" :key="r.id">
                                    <div class="text-[10px] truncate px-2 py-1 rounded-md bg-secondary/80 border border-border/50 text-foreground font-semibold shadow-sm" x-text="r.content"></div>
                                </template>
                                <template x-if="getDayRems(day).length > 2">
                                    <div class="text-[9px] font-mono font-bold text-primary bg-primary/10 inline-block px-1.5 rounded" x-text="'+' + (getDayRems(day).length - 2)"></div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            <!-- RIGHT COLUMN: Tasks -->
            <aside class="col-span-12 lg:col-span-3 space-y-6">
                <div class="gsap-panel bg-card border border-border rounded-[2rem] p-6 shadow-sm opacity-0 flex flex-col h-[520px]">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-xs font-bold text-muted-foreground uppercase tracking-widest flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-amber-500/50"></span> <span x-text="i18n[lang].quickTasksTitle"></span></h2>
                        <span class="text-[10px] font-mono bg-primary/10 text-primary px-2.5 py-1 rounded-full font-bold" x-text="tasks.filter(t => !t.completed).length"></span>
                    </div>

                    <form @submit.prevent="addTask()" class="mb-4 space-y-2">
                        <input type="text" x-model="newTask.title" :placeholder="i18n[lang].taskPlaceholder" required class="w-full bg-secondary/50 border border-border rounded-xl px-4 py-2.5 text-xs text-foreground focus:outline-none focus:ring-2 focus:ring-primary/50">
                        <div class="flex gap-2">
                            <input type="time" x-model="newTask.time" class="w-1/2 bg-secondary/50 border border-border rounded-xl px-4 py-2.5 text-xs font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-primary/50">
                            <button type="submit" class="w-1/2 bg-foreground text-background hover:opacity-90 rounded-xl text-xs font-bold py-2.5 transition shadow-md">+</button>
                        </div>
                    </form>

                    <div class="flex-1 overflow-y-auto space-y-2 pe-2 custom-scrollbar">
                        <template x-for="t in tasks" :key="t.id">
                            <label class="task-item flex items-start gap-3 p-3.5 rounded-2xl border transition-all cursor-pointer group hover:border-primary/40 relative overflow-hidden"
                                 :class="t.completed ? 'bg-secondary/30 border-border/40' : 'bg-background border-border shadow-sm'">
                                <input type="checkbox" :checked="t.completed" @change="toggleTask(t.id)" class="task-checkbox mt-0.5 w-4 h-4 rounded border-border text-primary focus:ring-0 transition-all">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-bold text-foreground mb-1 truncate" x-text="t.title"></p>
                                    <template x-if="t.due_time"><span class="text-[10px] font-mono text-primary bg-primary/10 px-1.5 py-0.5 rounded-md" x-text="'🕒 ' + t.due_time"></span></template>
                                </div>
                                <button type="button" @click.prevent="delTask(t.id)" class="opacity-0 group-hover:opacity-100 text-muted-foreground hover:text-red-500 transition px-1 absolute end-3 top-3">✕</button>
                            </label>
                        </template>
                        <template x-if="tasks.length === 0"><div class="text-xs text-muted-foreground/50 text-center py-4 italic" x-text="i18n[lang].noData"></div></template>
                    </div>
                </div>
                
                <div class="gsap-panel text-center p-5 bg-card border border-border rounded-[2rem] opacity-0 glass-panel shadow-sm hover:shadow-md transition-shadow">
                    <p class="text-[10px] font-black tracking-widest text-muted-foreground uppercase">**Made with Love ❤️ in Bahrain 🇧🇭**</p>
                </div>
            </aside>
        </div>
    </main>

    <!-- Reminders Modal -->
    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4 lg:p-0">
        <div class="modal-backdrop absolute inset-0 bg-background/80 backdrop-blur-xl opacity-0" @click="closeModal()"></div>
        <div class="modal-content relative bg-card w-full max-w-xl rounded-[2.5rem] border border-border shadow-2xl p-8 opacity-0 scale-90 translate-y-8">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-border">
                <div>
                    <h3 class="text-2xl font-black text-foreground mb-1" x-text="i18n[lang].dayModalTitle"></h3>
                    <p class="text-sm font-mono text-primary font-bold bg-primary/10 inline-block px-3 py-1 rounded-lg" x-text="activeDate"></p>
                </div>
                <button @click="closeModal()" class="w-10 h-10 flex items-center justify-center rounded-full bg-secondary text-muted-foreground hover:text-foreground hover:bg-border transition text-lg">✕</button>
            </div>
            <div class="mb-6 space-y-3 max-h-[40vh] overflow-y-auto pe-2 custom-scrollbar">
                <template x-for="r in modalRems" :key="r.id">
                    <div class="reminder-item flex items-center justify-between p-4 rounded-2xl bg-secondary/50 border border-border group hover:border-primary/40 transition">
                        <span class="text-sm text-foreground font-bold" x-text="r.content"></span>
                        <button @click="delReminder(r.id)" class="text-xs bg-background border border-border text-red-400 hover:text-red-500 hover:border-red-500/50 w-8 h-8 rounded-full opacity-0 group-hover:opacity-100 transition flex items-center justify-center">✕</button>
                    </div>
                </template>
                <template x-if="modalRems.length === 0"><div class="text-sm text-muted-foreground text-center py-8 bg-secondary/30 rounded-2xl border border-dashed border-border" x-text="i18n[lang].noRemindersYet"></div></template>
            </div>
            <form @submit.prevent="addReminder()" class="relative">
                <textarea x-model="newRem" :placeholder="i18n[lang].reminderInputPlaceholder" required class="w-full h-32 bg-background border border-border rounded-2xl p-5 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-primary/50 text-foreground placeholder:text-muted-foreground resize-none shadow-inner"></textarea>
                <div class="absolute bottom-4 end-4 flex gap-2">
                    <button type="button" @click="closeModal()" class="px-5 py-2.5 text-xs font-bold rounded-xl bg-secondary text-foreground hover:bg-border transition border border-border" x-text="i18n[lang].cancelBtn"></button>
                    <button type="submit" class="px-6 py-2.5 text-xs font-bold rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 shadow-lg shadow-primary/30 transition transform hover:-translate-y-0.5" x-text="i18n[lang].saveEntryBtn"></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Media Upload Modal (Avatar & Cover) -->
    <div x-show="mediaModalOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-4 lg:p-0">
        <div class="media-backdrop absolute inset-0 bg-background/80 backdrop-blur-xl opacity-0" @click="closeMediaModal()"></div>
        <div class="media-content relative bg-card w-full max-w-md rounded-[2.5rem] border border-border shadow-2xl p-8 opacity-0 scale-90 translate-y-8">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-border">
                <h3 class="text-xl font-black text-foreground" x-text="mediaTarget === 'avatar_img' ? i18n[lang].changeAvatar : i18n[lang].changeCover"></h3>
                <button type="button" @click="closeMediaModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-secondary text-muted-foreground hover:text-foreground hover:bg-border transition text-lg">✕</button>
            </div>
            <form @submit.prevent="submitMedia()" class="space-y-5">
                <div>
                    <label class="block text-xs font-bold text-muted-foreground mb-2" x-text="i18n[lang].imageURL"></label>
                    <input type="url" x-model="mediaUrl" placeholder="https://..." class="w-full bg-background border border-border rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary/50 text-foreground">
                </div>
                <div class="relative flex py-2 items-center">
                    <div class="flex-grow border-t border-border"></div>
                    <span class="flex-shrink-0 mx-4 text-xs font-bold text-muted-foreground uppercase" x-text="i18n[lang].or"></span>
                    <div class="flex-grow border-t border-border"></div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-muted-foreground mb-2" x-text="i18n[lang].uploadFile"></label>
                    <input type="file" x-ref="mediaFile" accept="image/*" class="w-full bg-background border border-border rounded-xl px-4 py-2.5 text-sm file:mr-4 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 text-foreground cursor-pointer">
                </div>
                <div class="p-3.5 rounded-2xl bg-secondary/80 border border-border/80 text-xs text-muted-foreground flex items-center gap-3">
                    <span class="text-base shrink-0">📐</span>
                    <span x-text="mediaTarget === 'avatar_img' ? (lang === 'ar' ? 'المتطلبات: صورة مربعة 1:1 — يتم القص والتحجيم تلقائياً إلى 400×400 بكسل.' : 'Requirements: Square 1:1 — automatically center-cropped and resized to 400×400px.') : (lang === 'ar' ? 'المتطلبات: صورة غلاف أفقية — يتم التحجيم التناسبي تلقائياً بما لا يتجاوز 1920×1080 بكسل.' : 'Requirements: Landscape cover — automatically resized up to 1920×1080px.')"></span>
                </div>
                <div class="pt-2 flex justify-end">
                    <button type="submit" class="px-6 py-3 text-sm font-bold rounded-xl bg-primary text-primary-foreground hover:bg-primary/90 shadow-lg shadow-primary/30 transition transform hover:-translate-y-0.5 w-full" x-text="i18n[lang].saveEntryBtn"></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Core App Engine -->
    <script>
        function calendarifyApp() {
            return {
                lang: '<?= htmlspecialchars($activeLang) ?>',
                darkMode: <?= $activeTheme === 'dark' ? 'true' : 'false' ?>,
                headerImg: '<?= htmlspecialchars($settings['header_img'] ?? $defaultSettings['header_img']) ?>',
                headerPosY: parseFloat('<?= htmlspecialchars($settings['header_pos_y'] ?? '42') ?>'),
                avatarImg: '<?= htmlspecialchars($settings['avatar_img'] ?? $defaultSettings['avatar_img']) ?>',
                
                isDragging: false, dragStart: 0, dragInitPos: 0,
                time: { h: '00', m: '00', s: '00', dayName: '' },
                currentMonth: new Date().getMonth(), currentYear: new Date().getFullYear(),
                
                reminders: <?= json_encode($reminders, JSON_UNESCAPED_UNICODE) ?>,
                tasks: <?= json_encode($tasks, JSON_UNESCAPED_UNICODE) ?>,
                birthdays: <?= json_encode($birthdays, JSON_UNESCAPED_UNICODE) ?>,
                currentQuote: <?= json_encode($initialQuote, JSON_UNESCAPED_UNICODE) ?>,
                
                modalOpen: false, activeDate: '', newRem: '',
                newTask: { title: '', time: '' }, newBday: { name: '', date: '' },
                toasts: [], toastIdSeed: 0,

                mediaModalOpen: false, mediaTarget: '', mediaUrl: '',

                i18n: {
                    ar: {
                        appTitle: 'كالنداريفاي برو', greeting: 'مرحباً بك مجدداً',
                        changeCover: 'تغيير الغلاف', changeAvatar: 'تغيير الصورة الشخصية', imageURL: 'رابط الصورة (URL)', or: 'أو', uploadFile: 'رفع من الجهاز',
                        navigation: 'الوصول السريع', birthdaysTitle: 'أعياد الميلاد',
                        bdayNamePlaceholder: 'اسم المناسبة', calendarSubtitle: 'التقويم الميلادي والهجري', today: 'اليوم',
                        quickTasksTitle: 'المهام السريعة', taskPlaceholder: 'مهمة جديدة...',
                        dayModalTitle: 'المفكرة والملاحظات', noRemindersYet: 'لا توجد ملاحظات مسجلة لهذا اليوم.',
                        reminderInputPlaceholder: 'اكتب ملاحظتك وسيتم حفظها فوراً...', cancelBtn: 'إلغاء', saveEntryBtn: 'حفظ التغييرات',
                        noData: 'لا يوجد بيانات',
                        navItems: [{ title: 'المالية', icon: '💳' }, { title: 'الأهداف', icon: '🎯' }, { title: 'المذكرات', icon: '📖' }, { title: 'العادات', icon: '🌿' }, { title: 'متفرقات', icon: '📦' }]
                    },
                    en: {
                        appTitle: 'Calendarify Pro', greeting: 'Welcome Back',
                        changeCover: 'Cover', changeAvatar: 'Change Avatar', imageURL: 'Image URL', or: 'OR', uploadFile: 'Upload File',
                        navigation: 'Quick Nav', birthdaysTitle: 'Birthdays',
                        bdayNamePlaceholder: 'Occasion Name', calendarSubtitle: 'Gregorian & Hijri Engine', today: 'Today',
                        quickTasksTitle: 'Fast Tasks', taskPlaceholder: 'New Task...',
                        dayModalTitle: 'Daily Log', noRemindersYet: 'No logs for this date yet.',
                        reminderInputPlaceholder: 'Type and persist instantly...', cancelBtn: 'Cancel', saveEntryBtn: 'Save Changes',
                        noData: 'No data found',
                        navItems: [{ title: 'Finance', icon: '💳' }, { title: 'Goals', icon: '🎯' }, { title: 'Journal', icon: '📖' }, { title: 'Habits', icon: '🌿' }, { title: 'Vault', icon: '📦' }]
                    }
                },
                monthNames: {
                    ar: ["يناير", "فبراير", "مارس", "أبريل", "مايو", "يونيو", "يوليو", "أغسطس", "سبتمبر", "أكتوبر", "نوفمبر", "ديسمبر"],
                    en: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]
                },
                dayNames: { ar: ["الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"], en: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] },

                init() {
                    this.tick(); setInterval(() => this.tick(), 1000);
                    this.$nextTick(() => this.runEntryAnimation());
                },

                addToast(message, type = 'success') {
                    const id = ++this.toastIdSeed; this.toasts.push({ id, message, type });
                    setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 3000);
                },
                animateToast(el, id) { gsap.fromTo(el, { opacity: 0, y: 20, scale: 0.9 }, { opacity: 1, y: 0, scale: 1, duration: 0.4, ease: "back.out(1.5)" }); },

                runEntryAnimation() {
                    const tl = gsap.timeline();
                    tl.to(".gsap-hero-img", { scale: 1, opacity: 1, duration: 1.5, ease: "power3.out" }, 0)
                      .fromTo(".gsap-navbar", { y: -20, opacity: 0 }, { y: 0, opacity: 1, duration: 1, ease: "power3.out" }, 0.2)
                      .fromTo(".gsap-profile", { scale: 0.8, opacity: 0, rotationY: -15 }, { scale: 1, opacity: 1, rotationY: 0, duration: 1.2, ease: "elastic.out(1, 0.75)" }, 0.4)
                      .fromTo(".gsap-title", { x: -20, opacity: 0 }, { x: 0, opacity: 1, duration: 1, ease: "power3.out" }, 0.5)
                      .fromTo(".gsap-clock", { x: 20, opacity: 0 }, { x: 0, opacity: 1, duration: 1, ease: "power3.out" }, 0.6)
                      .fromTo(".gsap-panel", { y: 30, opacity: 0 }, { y: 0, opacity: 1, duration: 0.8, stagger: 0.1, ease: "back.out(1.2)" }, 0.7);
                    this.animGrid();
                },
                animGrid() { this.$nextTick(() => { gsap.fromTo(".calendar-cell", { scale: 0.9, opacity: 0, y: 10 }, { scale: 1, opacity: 1, y: 0, duration: 0.4, stagger: 0.02, ease: "power2.out", overwrite: true }); }); },
                animItem(selector) { this.$nextTick(() => { gsap.fromTo(`${selector}:first-child`, { opacity: 0, x: -20, height: 0, marginBottom: 0 }, { opacity: 1, x: 0, height: "auto", marginBottom: "0.5rem", duration: 0.4, ease: "back.out(1.5)" }); }); },

                tick() { const d = new Date(); this.time = { h: String(d.getHours()).padStart(2, '0'), m: String(d.getMinutes()).padStart(2, '0'), s: String(d.getSeconds()).padStart(2, '0'), dayName: this.dayNames[this.lang][d.getDay()] }; },
                get todayDateFormatted() { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; },

                get daysInMonth() { return Array.from({ length: new Date(this.currentYear, this.currentMonth + 1, 0).getDate() }, (_, i) => i + 1); },
                get offset() { return new Date(this.currentYear, this.currentMonth, 1).getDay(); },
                changeMonth(d) { this.currentMonth += d; if(this.currentMonth > 11) { this.currentMonth = 0; this.currentYear++; } else if(this.currentMonth < 0) { this.currentMonth = 11; this.currentYear--; } this.animGrid(); },
                resetMonth() { this.currentMonth = new Date().getMonth(); this.currentYear = new Date().getFullYear(); this.animGrid(); },
                fmtDate(d) { return `${this.currentYear}-${String(this.currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`; },
                isToday(d) { const n = new Date(); return d === n.getDate() && this.currentMonth === n.getMonth() && this.currentYear === n.getFullYear(); },
                
                getHijriDay(d) {
                    try {
                        const dt = new Date(this.currentYear, this.currentMonth, d);
                        const locale = this.lang === 'ar' ? 'ar-SA-u-ca-islamic' : 'en-US-u-ca-islamic';
                        return new Intl.DateTimeFormat(locale, {day: 'numeric'}).format(dt);
                    } catch (e) { return ''; }
                },

                getDayRems(d) { const str = this.fmtDate(d); return this.reminders.filter(r => r.date === str); },
                get modalRems() { return this.reminders.filter(r => r.date === this.activeDate); },
                hasBday(d) { return this.birthdays.some(b => b.date === `${String(this.currentMonth + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`); },
                isBdayUpcoming(ds) { return ds >= `${String(new Date().getMonth() + 1).padStart(2, '0')}-${String(new Date().getDate()).padStart(2, '0')}`; },
                getBdayCount(ds) { const [m, d] = ds.split('-').map(Number); const n = new Date(); let t = new Date(n.getFullYear(), m - 1, d); if (t < n) t.setFullYear(n.getFullYear() + 1); return Math.ceil((t - n) / 86400000) === 0 ? 'Today' : `${Math.ceil((t - n) / 86400000)}d`; },

                async apiCall(action, payload = {}) { const res = await fetch(`?api=${action}`, { method: 'POST', body: JSON.stringify(payload) }); return await res.json(); },
                async addReminder() { if (!this.newRem.trim()) return; const p = { date: this.activeDate, content: this.newRem.trim() }; const res = await this.apiCall('add_reminder', p); if (res.ok) { this.reminders.push({ id: res.id, ...p }); this.newRem = ''; this.animItem('.reminder-item'); this.addToast('Saved'); } },
                async delReminder(id) { await this.apiCall('delete_reminder', { id }); this.reminders = this.reminders.filter(r => r.id !== id); this.addToast('Deleted', 'error'); },
                async addTask() { if (!this.newTask.title.trim()) return; const res = await this.apiCall('add_task', { title: this.newTask.title, due_time: this.newTask.time || null }); if (res.ok) { this.tasks.unshift({ id: res.id, title: this.newTask.title, completed: 0, due_time: this.newTask.time }); this.newTask = {title:'', time:''}; this.animItem('.task-item'); this.addToast('Task added'); } },
                async toggleTask(id) { const t = this.tasks.find(x => x.id === id); if (t) t.completed = t.completed ? 0 : 1; await this.apiCall('toggle_task', { id }); },
                async delTask(id) { await this.apiCall('delete_task', { id }); this.tasks = this.tasks.filter(t => t.id !== id); this.addToast('Task deleted'); },
                async addBday() { if (!this.newBday.name.trim() || !this.newBday.date.trim()) return; const res = await this.apiCall('add_birthday', this.newBday); if (res.ok) { this.birthdays.push({ id: res.id, name: this.newBday.name, date: this.newBday.date }); this.newBday = {name:'', date:''}; this.animItem('.birthday-item'); this.addToast('Birthday added'); } },
                async delBday(id) { await this.apiCall('delete_birthday', { id }); this.birthdays = this.birthdays.filter(b => b.id !== id); },

                openModal(day) {
                    this.activeDate = this.fmtDate(day); this.newRem = ''; this.modalOpen = true;
                    this.$nextTick(() => { gsap.to(".modal-backdrop", { opacity: 1, duration: 0.4, ease: "power2.out" }); gsap.to(".modal-content", { y: 0, scale: 1, opacity: 1, duration: 0.5, ease: "back.out(1.2)" }); gsap.fromTo(".reminder-item", { opacity: 0, x: -20 }, { opacity: 1, x: 0, duration: 0.4, stagger: 0.05, ease: "power2.out", delay: 0.2 }); });
                },
                closeModal() { gsap.to(".modal-content", { y: 30, scale: 0.95, opacity: 0, duration: 0.3, ease: "power2.in" }); gsap.to(".modal-backdrop", { opacity: 0, duration: 0.3, ease: "power2.in", onComplete: () => { this.modalOpen = false; gsap.set(".modal-content, .modal-backdrop", { clearProps: "all" }); }}); },
                
                openMediaModal(target) {
                    this.mediaTarget = target; this.mediaUrl = ''; if(this.$refs.mediaFile) this.$refs.mediaFile.value = '';
                    this.mediaModalOpen = true;
                    this.$nextTick(() => { gsap.to(".media-backdrop", { opacity: 1, duration: 0.4, ease: "power2.out" }); gsap.to(".media-content", { y: 0, scale: 1, opacity: 1, duration: 0.5, ease: "back.out(1.2)" }); });
                },
                closeMediaModal() { gsap.to(".media-content", { y: 30, scale: 0.95, opacity: 0, duration: 0.3, ease: "power2.in" }); gsap.to(".media-backdrop", { opacity: 0, duration: 0.3, ease: "power2.in", onComplete: () => { this.mediaModalOpen = false; gsap.set(".media-content, .media-backdrop", { clearProps: "all" }); }}); },
                async submitMedia() {
                    const fd = new FormData(); fd.append('target', this.mediaTarget);
                    if (this.mediaUrl) { fd.append('url', this.mediaUrl); }
                    else if (this.$refs.mediaFile && this.$refs.mediaFile.files[0]) { fd.append('file', this.$refs.mediaFile.files[0]); }
                    else { return this.addToast('No input provided', 'error'); }

                    try {
                        const res = await (await fetch('?api=update_media', { method: 'POST', body: fd })).json();
                        if (res.ok) {
                            if (this.mediaTarget === 'header_img') this.headerImg = res.path;
                            if (this.mediaTarget === 'avatar_img') this.avatarImg = res.path;
                            this.addToast('Updated successfully'); this.closeMediaModal();
                        } else throw new Error(res.error || 'Failed');
                    } catch(e) { this.addToast(e.message, 'error'); }
                },

                startDrag(e) { this.isDragging = true; this.dragStart = e.clientY; this.dragInitPos = this.headerPosY; },
                onDrag(e) { if (this.isDragging) this.headerPosY = Math.round(Math.max(0, Math.min(100, this.dragInitPos - ((e.clientY - this.dragStart) * 0.15)))); },
                async stopDrag() { if (!this.isDragging) return; this.isDragging = false; await this.apiCall('save_setting', { key: 'header_pos_y', value: this.headerPosY }); this.addToast('Position saved'); },
                
                async toggleTheme() { this.darkMode = !this.darkMode; document.documentElement.classList.toggle('dark', this.darkMode); await this.apiCall('save_setting', { key: 'theme_mode', value: this.darkMode ? 'dark' : 'light' }); },
                async toggleLanguage() { this.lang = this.lang === 'ar' ? 'en' : 'ar'; document.documentElement.lang = this.lang; document.documentElement.dir = this.lang === 'ar' ? 'rtl' : 'ltr'; await this.apiCall('save_setting', { key: 'lang', value: this.lang }); this.tick(); this.animGrid(); },
                async refreshQuote() { const res = await this.apiCall('get_quote'); if (res.ok) { gsap.fromTo(".gsap-title p", { opacity: 0, y: 5 }, { opacity: 1, y: 0, duration: 0.4 }); this.currentQuote = res.quote; } }
            }
        }
    </script>
</body>
</html>