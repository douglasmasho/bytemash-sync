# 🚀 Cron Setup Cheat Sheet (exec() Disabled)

**Problem:** exec() function is disabled on your server  
**Solution:** Set up cron manually (5 minutes) ✅

---

## 📋 Option 1: cPanel (Most Common)

```
1. Login → cPanel
2. Click → "Cron Jobs"
3. Add New Cron Job:
   
   Minute:  */5
   Hour:    *
   Day:     *
   Month:   *
   Weekday: *
   
   Command:
   wget -q -O - "https://YOUR-SITE.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1

4. Save → Done! ✅
```

**Replace:** `YOUR-SITE.com` with your actual domain

---

## 📋 Option 2: UptimeRobot (If No cPanel)

```
1. Sign up → https://uptimerobot.com (FREE)
2. Add New Monitor:
   
   Type:     HTTP(s)
   URL:      https://YOUR-SITE.com/wp-cron.php?doing_wp_cron
   Interval: 5 minutes

3. Save → Done! ✅
   
Bonus: You also get uptime monitoring!
```

---

## ✅ Verify It's Working

```
Wait: 5-10 minutes after setup

Check:
1. WP Admin → Amrod Sync → Dashboard
2. Look at "Scheduled Sync Monitoring"
3. Should see recent activity in logs

If working: You'll see timestamps updating ✅
If not working: Check troubleshooting below ⚠️
```

---

## 🔧 Troubleshooting

### Issue: Nothing happens after 10 minutes

**Check your URL:**
```
✅ Correct: https://your-site.com/wp-cron.php?doing_wp_cron
❌ Wrong:   https://your-site.com/wp-cron.php
❌ Wrong:   http://your-site.com/wp-cron.php?doing_wp_cron
```

**Try alternative command:**
```bash
curl -s "https://YOUR-SITE.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

---

### Issue: "Connection timeout"

**Solution:**
```
For external services:
- Set timeout to 60+ seconds

For cPanel:
- Add timeout to command:
  timeout 120 wget -q -O - "https://YOUR-SITE.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

---

### Issue: Cron runs but syncs don't start

**Quick Fix:**
```
1. WP Admin → Amrod Sync → Settings
2. Click "Save Settings" (don't change anything)
3. Wait 5 minutes
4. Check again
```

---

## 📞 Need More Help?

**Full Guide:**  
`documentation/CRON-SETUP-NO-EXEC.md`

**Diagnostics:**  
`https://your-site.com/wp-content/plugins/bytemash-woo-sync/diagnostics.php`

**Troubleshooting:**  
`documentation/TROUBLESHOOTING-LIVE-SERVER.md`

---

## 💡 Quick Tips

✅ **Do:**
- Use https:// if your site has SSL
- Test in browser first (URL should load)
- Wait 5-10 minutes before checking
- Clear all caches after setup

❌ **Don't:**
- Use http:// if you have https://
- Forget the `?doing_wp_cron` parameter
- Set interval less than 5 minutes
- Panic! This is normal and easy to fix

---

## 🎯 What You Get After Setup

```
✅ Automatic full sync: Daily at 00:30 GMT+2
✅ Automatic incremental sync: Every 5 hours
✅ No manual intervention needed
✅ Reliable execution
✅ Better than exec() method anyway!
```

---

## 📊 Method Comparison

| Method | Time | Difficulty | Reliability |
|--------|------|------------|-------------|
| cPanel | 5 min | ⭐ Easy | ⭐⭐⭐⭐⭐ |
| UptimeRobot | 10 min | ⭐ Easy | ⭐⭐⭐⭐⭐ |
| EasyCron | 10 min | ⭐ Easy | ⭐⭐⭐⭐ |
| cron-job.org | 10 min | ⭐ Easy | ⭐⭐⭐⭐ |

**Recommendation:** cPanel (if available) > UptimeRobot > Others

---

**That's it! Choose a method above and you're done.** 🎉

**Questions?** Read the full guides in `/documentation/` folder.

