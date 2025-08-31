# Okvir Duplicate Image Detector

## 🔍 Advanced WordPress Plugin for Duplicate Image Detection

A comprehensive WordPress plugin that uses **5 different algorithms** to detect and manage duplicate images in your media library. Features progressive analysis, safe deletion with reference replacement, and a sleek admin interface.

## ✨ Key Features

### 🧮 Multiple Detection Algorithms
- **File Hash**: Exact byte-for-byte matching (fastest)
- **Perceptual Hash**: DCT-based similarity detection
- **Color Histogram**: Color distribution analysis
- **Template Matching**: Multi-scale feature matching
- **Keypoint Matching**: SIFT-like robust detection

### 🛡️ Safety First
- **Progressive Analysis**: Uses faster methods first, advanced methods only when needed
- **Multiple Method Verification**: At least 2 methods must agree for duplicate detection
- **Reference Replacement**: Automatically updates all content references before deletion
- **Backup System**: Creates backups before any deletion
- **Rollback Capability**: Automatic rollback if deletion fails

### ⚡ Performance & Scalability
- **Batch Processing**: Configurable batch sizes with hard-coded 100 image limit
- **Background Processing**: WordPress cron integration for large libraries
- **Memory Management**: Optimized for large image collections
- **Progressive Loading**: Only processes what's needed

### 🎛️ Advanced Management
- **Comprehensive Admin Interface**: 5 specialized tabs for different functions
- **Duplicate Grouping**: Intelligent grouping of related duplicates
- **Content Scanning**: Finds image references across posts, pages, meta, and custom tables
- **Detailed Logging**: Complete processing logs with debugging information

## 📊 Algorithm Comparison

| Method | Speed | Memory | Exact Dupes | Similar Images | Transformations | Partial Detection |
|--------|-------|--------|-------------|----------------|-----------------|-------------------|
| File Hash | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ❌ | ❌ | ❌ |
| pHash | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ | ❌ |
| Histogram | ⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐⭐ | ⭐ |
| Template Match | ⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐ | ⭐⭐ | ⭐⭐⭐⭐ |
| Keypoint Match | ⭐ | ⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ | ⭐⭐⭐⭐⭐ |

## 🚀 Quick Start

1. **Install & Activate** the plugin
2. **Configure Methods** in Settings (File Hash + Perceptual Hash recommended for start)
3. **Start Analysis** from Dashboard or Analysis tab
4. **Review Duplicates** in the Duplicates tab
5. **Delete Safely** using the bulk deletion tools

## 🎯 Recommended Configurations

### Speed Optimized (Small Libraries)
- ✅ File Hash
- ✅ Perceptual Hash
- ❌ Other methods
- Batch Size: 50

### Balanced (Most Users)
- ✅ File Hash
- ✅ Perceptual Hash  
- ✅ Color Histogram
- ❌ Advanced methods
- Batch Size: 20

### Maximum Accuracy (Critical Use)
- ✅ All methods enabled
- Batch Size: 5
- Manual review recommended

## 🗄️ Database Schema

The plugin creates 8 dedicated tables:
- `okvir_image_signatures` - Unique algorithm signatures
- `okvir_image_analysis` - Per-image analysis results
- `okvir_duplicate_groups` - Duplicate group management
- `okvir_processing_queue` - Background processing queue
- Plus 4 supporting tables for links, members, logs, and references

## 📋 Requirements

- **WordPress**: 5.0+
- **PHP**: 7.4+
- **Extensions**: GD (required), Imagick (optional, recommended)
- **Memory**: 256MB+ recommended for image processing

## 🔧 Technical Details

### Algorithm Implementations
- **File Hash**: MD5 + SHA256 cryptographic hashing
- **Perceptual Hash**: 64-bit DCT-based fingerprinting
- **Color Histogram**: RGB + HSV distribution analysis
- **Template Match**: Multi-scale feature extraction with Sobel edges and Harris corners
- **Keypoint Match**: SIFT-inspired keypoint detection with descriptor matching

### Safety Mechanisms
- **Hard-coded Limits**: Maximum 100 images per batch (cannot be overridden)
- **Minimum Agreement**: 2+ methods must agree for duplicate detection
- **Reference Tracking**: Scans posts, meta, options, and custom tables
- **Backup System**: Automatic backup creation before deletion
- **Transaction Safety**: Rollback capability for failed operations

## 📝 Version History

### v1.0.0
- Initial release with 5 detection algorithms
- Progressive analysis system
- Safe deletion with reference replacement
- Background processing support
- Comprehensive admin interface

## 🤝 Credits

Developed by the Okvir Development Team as part of the WordPress Image Management Suite.

## 📄 License

GPL v2 or later - See LICENSE file for details.
