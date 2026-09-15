<?php
// 设置时区为中国，确保日期正确
date_default_timezone_set('Asia/Shanghai');

// --- 数据库连接信息 ---
$host = 'localhost';
$dbname = 'amazon_stock';
$user = 'amazon_stock';
$pass = 'kiosk123';

echo "Cron job started at: " . date('Y-m-d H:i:s') . "\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- 核心逻辑 ---

// 获取 "YYYY/M/D" 格式的日期，用于匹配 JSON 中的键
$today_date_key = date('Y/n/j'); 
echo "Today's date key to check: " . $today_date_key . "\n";

$update_count = 0;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->query("SELECT id, data FROM amazon_stock_rows");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updateStmt = $pdo->prepare("UPDATE amazon_stock_rows SET data = :data WHERE id = :id");

    foreach ($rows as $row) {
        $id = $row['id'];
        $rowData = json_decode($row['data'], true);

        if (!$rowData) {
            continue;
        }

        if (isset($rowData[$today_date_key])) {
            $shipment_value = floatval($rowData[$today_date_key]);
            $current_stock = floatval($rowData['今日库存'] ?? 0);

            if ($shipment_value > 0) {
                // 更新今日库存
                $rowData['今日库存'] = $current_stock + $shipment_value;
                
                $updated_json_data = json_encode($rowData, JSON_UNESCAPED_UNICODE);
                $updateStmt->execute([
                    ':data' => $updated_json_data,
                    ':id' => $id
                ]);
                
                echo "SUCCESS: Row ID {$id} stock updated.\n";
                $update_count++;
            }
        }
    }

    // ===== 主要修改：如果今天有更新，则记录日期 =====
    if ($update_count > 0) {
        // 获取 'YYYY-MM-DD' 格式的日期，用于存入数据库
        $today_sql_format = date('Y-m-d');

        // 使用 INSERT IGNORE 来防止因日期重复导致报错
        $logDateStmt = $pdo->prepare("INSERT IGNORE INTO amazon_stock_processed_dates (processed_date) VALUES (:p_date)");
        $logDateStmt->execute([':p_date' => $today_sql_format]);
        
        echo "LOG SUCCESS: Today's date ({$today_sql_format}) has been logged as processed.\n";
    }

    // 提交所有数据库更改
    $pdo->commit();

    echo "-------------------------------------------\n";
    echo "Task completed successfully.\n";
    echo "Total rows updated: " . $update_count . "\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    die("An error occurred: " . $e->getMessage());
}
?>