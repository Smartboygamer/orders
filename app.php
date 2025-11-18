<?php

$dbHost = "test2.topuprush.top";
$dbName = "topuprus_test";
$dbUser = "topuprus_test";
$dbPass = "topuprus_test";

$discordBotToken = "MTQ0MDA3MjE5NjgzODkyMDI5Mw.GX75uW.u_dSs38PvTbKFDE-smvNpEFs_6NnXvYiDrn60I"; // put your bot token
$channelId = "1440076017359392828";      // Discord channel ID

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[OK] Connected to DB.\n";
} catch (PDOException $e) {
    die("[ERROR] DB connection failed: " . $e->getMessage());
}

echo "[OK] Monitoring orders table...\n";

$sentMessages = [];    // order_id => message_id
$orderStatuses = [];   // order_id => last known status
$pingReplies  = [];    // order_id => reply_id

function statusColor($status) {
    switch(strtolower($status)) {
        case 'completed': return 0x2ECC71; // green
        case 'processing': return 0x3498DB; // blue
        case 'hold': return 0xE67E22; // orange
        case 'pending': return 0xF1C40F; // yellow
        default: return 0x95A5A6; // gray
    }
}

while (true) {
    $stmt = $pdo->query("SELECT * FROM orders ORDER BY id ASC");

    while ($order = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id          = $order['id'];
        $user_id     = $order['user_id'];
        $product_id  = $order['product_id'];
        $qty         = $order['quantity'];
        $amount      = $order['amount'];
        $status      = $order['status'];
        $account_raw = $order['account_info'];
        $webhookSent = $order['webhook_sent'];

        // User name
        $user_name = "User #$user_id";
        try {
            $q = $pdo->prepare("SELECT name FROM users WHERE id=? LIMIT 1");
            $q->execute([$user_id]);
            $u = $q->fetch(PDO::FETCH_ASSOC);
            if ($u) $user_name = $u['name'];
        } catch (Exception $e) { }

        // Product title
        $product_title = "Product #$product_id";
        try {
            $q = $pdo->prepare("SELECT title FROM products WHERE id=? LIMIT 1");
            $q->execute([$product_id]);
            $p = $q->fetch(PDO::FETCH_ASSOC);
            if ($p) $product_title = $p['title'];
        } catch (Exception $e) { }

        // Player ID
        $player_id = "N/A";
        if ($account_raw) {
            $json = json_decode($account_raw, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($json['player_id'])) {
                $player_id = $json['player_id'];
            }
        }

        // Embed
        $embed = [
            "title" => "📦 Order Update",
            "color" => statusColor($status),
            "fields" => [
                ["name" => "Order ID", "value" => (string)$id, "inline" => true],
                ["name" => "User", "value" => "$user_name (ID: $user_id)", "inline" => true],
                ["name" => "Item", "value" => $product_title, "inline" => true],
                ["name" => "Quantity", "value" => (string)$qty, "inline" => true],
                ["name" => "Amount", "value" => (string)$amount, "inline" => true],
                ["name" => "Status", "value" => ucfirst($status), "inline" => true],
                ["name" => "Player ID", "value" => $player_id, "inline" => false]
            ],
            "timestamp" => date(DATE_ATOM)
        ];

        $payload = ['embeds' => [$embed]];

        // -----------------------------
        // New message
        // -----------------------------
        if (!isset($sentMessages[$id])) {
            // Ping @everyone if processing
            if ($status === 'processing') $payload['content'] = "@everyone";

            $ch = curl_init("https://discord.com/api/v10/channels/$channelId/messages");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bot $discordBotToken",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code == 200 || $http_code == 201) {
                $data = json_decode($response, true);
                $sentMessages[$id] = $data['id'] ?? null;
                $orderStatuses[$id] = $status;

                // Reply ping for processing
                if ($status === 'processing') {
                    $replyPayload = [
                        'content' => "@everyone",
                        'message_reference' => ['message_id' => $sentMessages[$id]]
                    ];
                    $ch = curl_init("https://discord.com/api/v10/channels/$channelId/messages");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization: Bot $discordBotToken",
                        "Content-Type: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyPayload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $replyResponse = curl_exec($ch);
                    curl_close($ch);
                    $replyData = json_decode($replyResponse, true);
                    $pingReplies[$id] = $replyData['id'] ?? null;
                }

                // Mark webhook_sent
                $pdo->prepare("UPDATE orders SET webhook_sent = 1 WHERE id = ?")->execute([$id]);
                echo "[" . date("H:i:s") . "] Order $id sent!\n";
            }
        } else {
            // -----------------------------
            // Status changed
            // -----------------------------
            if ($orderStatuses[$id] !== $status) {
                $messageId = $sentMessages[$id];

                // Update embed
                $ch = curl_init("https://discord.com/api/v10/channels/$channelId/messages/$messageId");
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bot $discordBotToken",
                    "Content-Type: application/json"
                ]);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);

                // Handle ping reply
                if ($status === 'processing' && !isset($pingReplies[$id])) {
                    // Send @everyone reply
                    $replyPayload = [
                        'content' => "@everyone",
                        'message_reference' => ['message_id' => $messageId]
                    ];
                    $ch = curl_init("https://discord.com/api/v10/channels/$channelId/messages");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization: Bot $discordBotToken",
                        "Content-Type: application/json"
                    ]);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($replyPayload));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $replyResponse = curl_exec($ch);
                    curl_close($ch);
                    $replyData = json_decode($replyResponse, true);
                    $pingReplies[$id] = $replyData['id'] ?? null;

                } elseif ($orderStatuses[$id] === 'processing' && $status !== 'processing' && isset($pingReplies[$id])) {
                    // Delete @everyone reply if status changed away from processing
                    $replyId = $pingReplies[$id];
                    $ch = curl_init("https://discord.com/api/v10/channels/$channelId/messages/$replyId");
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization: Bot $discordBotToken"
                    ]);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch);
                    curl_close($ch);
                    unset($pingReplies[$id]);
                }

                $orderStatuses[$id] = $status;
                echo "[" . date("H:i:s") . "] Order $id updated to $status\n";
            }
        }
    }

    sleep(2);
}
