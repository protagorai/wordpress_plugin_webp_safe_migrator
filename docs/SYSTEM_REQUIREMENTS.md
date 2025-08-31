# WebP Safe Migrator - System Requirements

## 📋 Table of Contents

- [Container Engine Requirement](#container-engine-requirement)
- [Installation by Operating System](#installation-by-operating-system)
  - [Windows](#windows)
  - [Linux](#linux)
  - [macOS](#macos)
- [Alternative: Docker](#alternative-docker)
- [Verification](#verification)
- [Quick Start After Installation](#quick-start-after-installation)
- [Troubleshooting](#troubleshooting)
- [System Resource Requirements](#system-resource-requirements)
- [Network Requirements](#network-requirements)

## 🐳 **Container Engine Requirement**

WebP Safe Migrator requires **Podman** (recommended) or **Docker** to run the WordPress development environment.

### **Why Podman is Recommended:**
- ✅ **Open Source**: Apache 2.0 license (commercial-friendly)
- ✅ **Rootless**: Better security model
- ✅ **Docker Compatible**: Same commands and syntax
- ✅ **No Daemon**: Lightweight, starts faster

## 📦 **Installation by Operating System**

### **Windows**
```powershell
# Option 1: Download installer
# Visit: https://podman.io/getting-started/installation#windows
# Download and run Podman installer

# Option 2: Chocolatey
choco install podman

# Option 3: Winget  
winget install RedHat.Podman
```

### **Linux**

#### **Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install podman
```

#### **RHEL/CentOS/Fedora:**
```bash
sudo dnf install podman
# OR on older systems:
sudo yum install podman
```

#### **Arch Linux:**
```bash
sudo pacman -S podman
```

### **macOS**
```bash
# Option 1: Homebrew (recommended)
brew install podman

# Option 2: MacPorts
sudo port install podman
```

## 🔧 **Alternative: Docker**

If you prefer Docker, you can use it instead:

### **Install Docker:**
- **Windows/macOS**: Download Docker Desktop
- **Linux**: Follow Docker installation guide for your distribution

### **Use Docker Instead of Podman:**
Simply replace `podman` with `docker` in the scripts:
```bash
# Find and replace in scripts:
sed 's/podman/docker/g' webp-migrator-simple.sh > webp-migrator-docker.sh
```

## ✅ **Verification**

After installation, verify your container engine works:

### **Podman:**
```bash
podman --version
podman run hello-world
```

### **Docker:**
```bash
docker --version  
docker run hello-world
```

## 🚀 **Quick Start After Installation**

Once Podman/Docker is installed:

### **Windows:**
```cmd
webp-migrator-simple.bat
```

### **Linux/macOS:**
```bash
./webp-migrator-simple.sh
```

## 🔧 **Troubleshooting**

### **Permission Issues (Linux):**
```bash
# Enable rootless containers:
sudo usermod --add-subuids 100000-165535 --add-subgids 100000-165535 $USER
podman system migrate
```

### **Windows WSL Issues:**
```bash
# Enable WSL 2:
wsl --update
wsl --set-default-version 2
```

### **Docker Desktop Issues:**
- Ensure Docker Desktop is running
- Check virtualization is enabled in BIOS
- Restart Docker service if needed

## 📋 **System Resource Requirements**

### **Minimum:**
- **RAM**: 4GB available
- **Storage**: 5GB free space
- **CPU**: 2 cores recommended

### **Recommended:**
- **RAM**: 8GB available
- **Storage**: 10GB free space (for image caching)
- **CPU**: 4+ cores for faster builds

## 🌐 **Network Requirements**

### **For Initial Setup:**
- Internet connection for downloading Docker images (~1.3GB total)
- Access to docker.io registry
- Access to raw.githubusercontent.com (for WP-CLI)

### **For Offline Use:**
After initial download, can run completely offline using pre-downloaded images.

---

**Once Podman or Docker is installed and verified, WebP Safe Migrator will work seamlessly across all platforms!** 🚀
