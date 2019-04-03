See:
- php artisan list | grep fingerprint

Setup Cronjob:
- https://www.rosehosting.com/blog/ubuntu-crontab/ then add this schedule:
```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /media/axquired24/Important/MyWeb/webproject/mikti-visitor/fingerprint-client-2 && php artisan schedule:run >> /dev/null 2>&1

systemctl restart cron
```