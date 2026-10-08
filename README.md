# Ticari — Saha Satış, Sözleşme ve Hukuk Takip Paneli

Ticari işletmelere yönelik abonelik satışı yapan bir bayi için geliştirilmiş, rol tabanlı **satış, sözleşme, tahsilat ve hukuk takip yönetim paneli**. PHP 8.3 + MSSQL üzerine kurulu; AdminLTE 4 arayüzü, merkezi cron sistemi, çok kanallı bildirim (e‑posta / SMS / WhatsApp / Web Push) ve kurulabilir PWA desteği içerir.

> ⚠️ Bu depo, üretimdeki bir sistemin **maskeli** (örnek alan adı / IP / firma / kişi bilgisi) genel kopyasıdır. `config/` ve sırlar dahil değildir; `config/*.example.php` dosyaları örnek olarak verilmiştir.

---

## Öne çıkan özellikler

| Alan | Açıklama |
|---|---|
| 🧾 **Cari & sözleşme** | Cari kartları, sözleşme oluşturma, ödeme planı ve stok hareketleri, toplu Excel içe aktarma |
| 💰 **Tahsilat & senet** | Tahsilat ajandası, ödenecek senetler, aylık / personel bazlı tahsilat raporları |
| 🏦 **Banka entegrasyonu** | Hesap hareketlerinin otomatik senkronu, dekont ile cari eşleştirme, IBAN yönetimi |
| ⚖️ **Hukuk takip** | İcra dosyaları, taraflar, icra daireleri, işleyen faizin günlük otomatik hesaplanması, icra ajandası |
| 🚨 **Tespit & suç duyurusu** | Lisanssız kullanım tespitleri, statü bazlı takip, ihtarname yazdırma, satılan / satılmayan / almayan raporları |
| 👥 **Sorumlu atama** | Kayıtları saha personeline atama; atamada WhatsApp ile otomatik bilgilendirme |
| 📊 **Raporlar** | Sezon bazlı satış / tahsilat / ülke / personel raporları, Excel çıktısı |
| 📍 **Saha takibi** | Personel cihaz konum takibi, bölge bazlı yetkilendirme |
| ✉️ **Çok kanallı bildirim** | E‑posta, SMS, WhatsApp (bot + webhook) ve **Web Push** (VAPID, bağımlılıksız) |
| 📱 **Kurulabilir PWA** | Manifest, service worker, masaüstü / mobil bildirim |
| ⏱️ **Merkezi cron** | Zamanlanmış görevler panelden yönetilir; çalışma geçmişi, overlap kilidi, telafi, manuel tetik |
| 🔐 **Yetkilendirme** | Menü / sayfa bazlı departman yetkileri (görüntüle / ekle / düzenle / sil / yalnız kendi kayıtları) |
| 🗂️ **Sürüm notları** | Panelden sürüm geçmişi takibi |

## Teknoloji

- **Backend:** PHP 8.3 (framework yok), MSSQL (`sqlsrv`)
- **Frontend:** AdminLTE 4, server-side DataTables, Select2, SweetAlert2
- **Bildirim:** PHPMailer, Evolution API (WhatsApp), VAPID Web Push (ES256 + aes128gcm, yalnız openssl/curl)
- **Sunucu:** IIS (`web.config` dahildir), Plesk zamanlanmış görev ile tek dakikalık `runner.php`

## Dizin yapısı

```
admin/
├── pages/        Panel sayfaları (cari, sözleşme, tahsilat, hukuk, raporlar, tanımlar)
├── includes/     Ortak bileşenler (yetki, bildirim, WhatsApp, Web Push, rapor gövdeleri)
├── api/          Dahili uç noktalar (banka, bildirim, konum, PWA, WhatsApp webhook)
├── cron/         Merkezi cron: runner.php → tasks.php → gorevler/<kod>.php
└── assets/       CSS, JS, görseller
config/           database.example.php, whatsapp.example.php (gerçek config hariç)
index.php         Giriş
```

## Kurulum (özet)

1. `config/database.example.php` → `config/database.php` olarak kopyalayın, MSSQL bilgilerinizi girin.
2. `config/whatsapp.example.php` → `config/whatsapp.php` (WhatsApp kullanılacaksa).
3. `composer install`
4. Web kökünü `index.php`'ye yönlendirin (IIS `web.config` dahildir).
5. Zamanlanmış görev olarak her dakika `php admin/cron/runner.php` çalıştırın.

## Notlar

- Alan adı, IP, firma adı, telefon ve e‑posta değerleri örnektir (maskeli sürüm).
- API anahtarları ve entegrasyon şifreleri kodda değil, veritabanındaki entegrasyon / ayar tablolarında tutulur.
- `config/`, `temp/`, `logs/` ve editör geçmişi klasörleri `web.config` kuralıyla web erişimine kapalıdır.

## Geliştirici

**Batuhan Kahraman** · [GitHub](https://github.com/Batuhan-Kahraman35)
