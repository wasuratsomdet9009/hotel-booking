<?php
$plain_password = "12345678";
$hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);
echo "Username: เก้า<br>";
echo "Role: admin<br>";
echo "Plain Password (for your reference, DO NOT store this): " . htmlspecialchars($plain_password) . "<br>";
echo "Hashed Password (to use in SQL): " . htmlspecialchars($hashed_password);
?>