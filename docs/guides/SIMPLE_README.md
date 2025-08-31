# WebP Safe Migrator - Elegant Minimal Solution

## 🎯 **One Command. Just Works.**

### **Windows:**
```cmd
webp-migrator-simple.bat        # Start everything
webp-migrator-simple.bat stop   # Stop everything  
webp-migrator-simple.bat clean  # Clean everything
```

### **Linux/macOS:**
```bash
./webp-migrator-simple.sh       # Start everything
./webp-migrator-simple.sh stop  # Stop everything
./webp-migrator-simple.sh clean # Clean everything
```

## 🚀 **What You Get:**

- **WordPress**: http://localhost:8080/wp-admin
- **Login**: `admin` / `admin123`
- **Plugin**: Already installed at Media → WebP Migrator
- **Database**: phpMyAdmin at http://localhost:8081

## 📋 **That's It.**

**No configuration files. No complex setup. No directories to navigate.**

**Just one script that works every time.** ✨

---

### Technical Details (If You Care):
- **Database**: `wordpress` with user `wpuser/wppass`
- **Network**: `webp-migrator-net` for container communication
- **Volumes**: Plugin source mounted for development
- **Ports**: 8080 (WordPress), 8081 (phpMyAdmin), 3307 (MySQL)

### If Something Goes Wrong:
1. **Run clean**: `webp-migrator-simple.bat clean`
2. **Run again**: `webp-migrator-simple.bat`
3. **If uploads fail**: `bin\manage\fix-uploads-ownership.bat` or quick fix: `bin\manage\fix-uploads-now.bat` (Windows) or `./bin/manage/fix-uploads-ownership.sh` (Linux/macOS)
4. **That's it.**
