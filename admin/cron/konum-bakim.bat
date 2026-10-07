@echo off
set LOG=d:\Inetpub\vhosts\ornekproje.com\ticari.ornekproje.com\admin\cron\logs\konum-bakim.log
echo [%date% %time%] >> "%LOG%"
"C:\Program Files (x86)\Plesk\Additional\PleskPHP84\php.exe" "d:\Inetpub\vhosts\ornekproje.com\ticari.ornekproje.com\admin\cron\konum-bakim.php" >> "%LOG%" 2>&1
echo. >> "%LOG%"
