<?php
$newPassword = "Minh123";
$hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
echo "Hash mới: " . $hashedPassword . "\n";

var_dump(password_verify("Minh123", $hashedPassword)); // Phải ra true
?>
