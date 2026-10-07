# OrnekYazilim Ticari

Ticari yönetim sistemi - PHP + MSSQL

## 📋 Gereksinimler

- PHP 7.4+
- MSSQL Server
- IIS / Apache
- PHP SQLSRV Extension

## 🚀 Kurulum

### 1. Hassas Dosyaları Yapılandırın

```bash
# Config dosyalarını kopyalayın
copy config\database.example.php config\database.php
copy config\mail.example.php config\mail.php
copy config\sms.example.php config\sms.php
```

### 2. Veritabanını Oluşturun

```sql
-- config/ticari_ornekyazilim_DB.sql dosyasını MSSQL'de çalıştırın
```

### 3. Config Dosyalarını Düzenleyin

**config/database.php:**
- Veritabanı bağlantı bilgilerinizi girin

**config/mail.php:**
- SMTP ayarlarınızı yapılandırın

**config/sms.php:**
- SMS provider bilgilerinizi girin

## 📁 Proje Yapısı

```
ticari.ornekproje.com/
├── admin/              # Yönetim paneli
│   ├── assets/        # CSS, JS, resimler
│   ├── includes/      # Header, sidebar
│   └── pages/         # Admin sayfaları
├── config/            # Yapılandırma dosyaları
├── logs/              # Log dosyaları
└── index.php          # Ana sayfa
```

## 🔐 Güvenlik

- `.gitignore` ile hassas dosyalar korunur
- Config dosyaları repo'da paylaşılmaz
- Şifreler hash'lenir (password_hash)

## 👨‍💻 Geliştirici

**Batuhan Kahraman**
- 📧 gelistirici@ornekproje.com.tr
- 📞 +90 500 000 00 01
- 🔗 [GitHub](https://github.com/Batuhan-Kahraman35)

## 📝 Lisans

OrnekYazilim © 2025
