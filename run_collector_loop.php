<?php

while (true) {
    echo "[" . date('Y-m-d H:i:s') . "] Обновляю цены Роснефти...\n";

    passthru('php collect_rosneft.php', $code);

    if ($code !== 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Ошибка обновления, код: {$code}\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Следующее обновление через 60 секунд\n\n";

    sleep(60);
}