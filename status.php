<?php
require __DIR__ . '/vendor/autoload.php';

use Discord\Discord;
use Discord\WebSockets\Intents;

// ------------------- CONFIG -------------------
$BOT_TOKEN   = 'MTQ0MDA3MjE5NjgzODkyMDI5Mw.GX75uW.u_dSs38PvTbKFDE-smvNpEFs_6NnXvYiDrn60I';
$DB_HOST     = 'test2.topuprush.top';
$DB_NAME     = 'topuprus_test';
$DB_USER     = 'topuprus_test';
$DB_PASS     = 'topuprus_test';
// ---------------------------------------------

// Connect to MySQL
try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[OK] Connected to DB.\n";
} catch (PDOException $e) {
    die("[ERROR] DB connection failed: " . $e->getMessage());
}

// Start Discord bot
$discord = new Discord([
    'token'   => $BOT_TOKEN,
    'intents' => Intents::GUILD_MESSAGES | Intents::MESSAGE_CONTENT | Intents::GUILDS,
]);

$discord->on('init', function (Discord $discord) use ($pdo) {
    echo "Bot is online! Listening for /status commands...\n";

    $discord->on('message', function ($message) use ($pdo) {
        // Ignore bot messages
        if ($message->author->bot) return;

        $content = trim($message->content);

        // Check if the message starts with /status
        if (str_starts_with($content, '/status')) {
            $parts = explode(' ', $content);

            if (count($parts) !== 3) {
                $message->reply("Usage: /status [order_id] [status]\nExample: /status 1 completed");
                return;
            }

            $orderId = intval($parts[1]);
            $status  = strtolower($parts[2]);

            $allowedStatuses = ['completed', 'cancelled', 'hold', 'processing'];

            if (!in_array($status, $allowedStatuses)) {
                $message->reply("Invalid status. Allowed: completed, cancelled, hold, processing");
                return;
            }

            // Update the order in DB
            $stmt = $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $orderId]);

            if ($stmt->rowCount() > 0) {
                $message->reply("✅ Order #$orderId status updated to `$status`.");
            } else {
                $message->reply("❌ Order #$orderId not found.");
            }
        }
    });
});

$discord->run();
