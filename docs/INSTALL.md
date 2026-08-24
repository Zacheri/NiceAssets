# Installing ATR Inventory — Step by Step

This guide is written for someone who does not think of themselves as "technical."
Follow it top to bottom and you will have a working inventory system on your Mac
that your whole office network can use.

You need:

- A Mac (Apple Silicon or Intel) running a recent macOS
- Internet access (one time, to download the required programs)
- Your Mac's administrator password (you'll be asked for it once)

## Step 1 — Put the folder somewhere permanent

Copy the `ATR` folder to a location that will not get cleaned up, for example:

```
/Users/yourname/Documents/ATR
```

Everything (data, backups, settings) lives inside this one folder from here on.
If you ever move the folder, run the installer again afterwards (Step 3) and all
scheduled jobs will re-point to the new location automatically.

## Step 2 — Open Terminal

1. Press `Command + Space`, type **Terminal**, press Enter.
2. A black window appears. This is normal — it is where we run one command.

## Step 3 — Run the installer

Type (or copy-paste) this into Terminal, then press Enter:

```
cd ~/Documents/ATR && ./install/install.sh
```

> If you put the folder somewhere else, use that path instead of `~/Documents/ATR`.

Now the computer does the work. You will see lines like:

- `Installing nginx…` — the program that serves the web page
- `Starting PostgreSQL…` — the program that stores your data
- `Loading schema… / Loading base seed…` — building your database (users and settings only — no sample data)
- `Wrote nginx server config (port 8080)`
- `Scheduled com.atr.backup` (and two more) — the automatic jobs

**When you are asked for your Mac password, type it and press Enter.**
(Nothing appears on screen while you type a password — this is normal.)

It takes a few minutes the first time (it downloads programs). Do not close the
window while it runs. When it finishes you will see a big box like:

```
 ATR Inventory is installed.
   Local:    http://127.0.0.1:8080
   Network:  http://192.168.1.24:8080
   Sign in:  admin / Admin1234
```

## Step 4 — Open it in a browser

- **On the Mac that runs it:** open a browser and go to `http://127.0.0.1:8080`
- **On any other computer on the same Wi‑Fi/office network:** go to the
  "Network" address it printed (e.g. `http://192.168.1.24:8080`)

Sign in with **admin / Admin1234**.

### Important — change the password now

1. Click **Admin** (left menu) → **Users**
2. In the "Edit user" section at the bottom, choose **admin**
3. Type a new password (at least 8 characters) in *New password*
4. Click **Save user**

## Step 5 — Start using it

- **Add your first asset:** Assets → **+ New Asset**. Fill in at least the Asset
  Tag Number, then pick a category, site, and location.
- **Add photos:** Photos → upload pictures (they can be reused by many assets).
  On an asset page you can link gallery photos and mark one as the thumbnail.
- **Check things out:** open an asset → **Check out** → pick the person and an
  optional due date.
- **Scan with a USB scanner:** point it at a QR label printed from any asset
  page (click **⋯ → Replicate** is not needed — use **QR label** or **Print sheet**).
  Scanning just opens/searches the asset. No special setup.
- **Look at everything:** Reports → pick a report. Use **Export Excel (formulas)**
  if you want the spreadsheet to keep calculating depreciation live.

## What runs automatically (no action needed)

| Job | When | What |
|-----|------|------|
| Backup | Every day at 2:00 AM | Saves the database + photos to `storage/backups` |
| Weekly report | Saturdays, 6:00 PM | Summary of Mon–Fri activity (Reports → Weekly) |
| Alert sweep | Every 15 minutes | Sends due email alerts (warranty, low stock, depreciation) |
| The web app itself | Always | Restarts itself automatically after a reboot |

## If something goes wrong

| Symptom | Fix |
|---------|-----|
| Page won't load | Wait 30 seconds, reload. If still bad: run `php bin/health.php` in Terminal (from the ATR folder) and read what it says. |
| "Dependencies are not installed" page | Run `./install/install.sh` again — it repairs everything. |
| Forgot admin password | `php bin/reset_admin_password.php admin NewPassword123` (from the ATR folder) |
| Mac was asleep when the 2 AM backup ran | The backup will run at the next wake — this is normal. |
| Moved the ATR folder | Re-run `./install/install.sh` from the new location. |
| Need to start over | `./install/uninstall.sh` (keeps your database unless you say otherwise) |

## About the network address

The "Network" address is this Mac's address on your office network. If your
network uses DHCP (typical), it can change after a reboot or network outage. If
other computers can no longer reach the app, reload the installer's instructions
by checking the Mac's address: open System Settings → Network → Wi‑Fi/Ethernet →
Details → Status. You can also ask your IT person to reserve a fixed address for
the Mac so the URL never changes.
