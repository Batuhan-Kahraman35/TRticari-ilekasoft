<?php
/**
 * Destek Widget — Yüzen Buton + Badge + Polling
 *
 * Kullanım (footer.php içinde):
 *   <?php
 *   $currentPage = basename($_SERVER['PHP_SELF']);
 *   $widgetHaric = ['destek.php', 'destek-detay.php', 'login.php', 'register.php'];
 *   if (Auth::check() && !in_array($currentPage, $widgetHaric)) {
 *       include __DIR__ . '/destek-widget.php';
 *   }
 *   ?>
 *
 * Sadece login sonrası sayfalarda include edin.
 * destek.php ve destek-detay.php'de kullanmayın.
 */
?>

<!-- ═══ YÜZEN DESTEK BUTONU ═══ -->
<a href="/admin/destek" id="destekWidget" title="Destek Talepleri">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 1a5 5 0 0 0-5 5v1h1a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a6 6 0 1 1 12 0v6a2.5 2.5 0 0 1-2.5 2.5H9.366a1 1 0 0 1-.866.5h-1a1 1 0 1 1 0-2h1a1 1 0 0 1 .866.5H11.5A1.5 1.5 0 0 0 13 12h-1a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h1V6a5 5 0 0 0-5-5"/>
    </svg>
    <span id="destekBadge"></span>
</a>

<style>
#destekWidget {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    box-shadow: 0 4px 15px rgba(0,0,0,.25);
    z-index: 9999;
    transition: transform .2s, box-shadow .2s;
}
#destekWidget:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(0,0,0,.35);
    color: #fff;
}
#destekBadge {
    display: none;
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 10px;
    background: #dc3545;
    color: #fff;
    font-size: .7rem;
    font-weight: 700;
    line-height: 20px;
    text-align: center;
}
@keyframes destekShake {
    0%, 100% { transform: rotate(0deg); }
    15% { transform: rotate(12deg); }
    30% { transform: rotate(-10deg); }
    45% { transform: rotate(8deg); }
    60% { transform: rotate(-6deg); }
    75% { transform: rotate(3deg); }
}
#destekWidget.shake {
    animation: destekShake .6s ease-in-out;
}
</style>

<script>
(function() {
    var POLLING_INTERVAL = 60000; // 60 saniye
    var BILDIRIM_URL     = '/admin/api/destek-bildirim.php';
    var LS_KEY           = 'destek_son_kontrol';

    var badge  = document.getElementById('destekBadge');
    var widget = document.getElementById('destekWidget');

    function getSonKontrol() {
        var val = localStorage.getItem(LS_KEY);
        if (!val) {
            // İlk açılışta 7 gün öncesini başlangıç al → mevcut okunmamış yanıtlar yakalanır
            var yediGunOnce = new Date(Date.now() - 7 * 24 * 60 * 60 * 1000);
            val = yediGunOnce.toISOString();
            localStorage.setItem(LS_KEY, val);
        }
        return val;
    }

    function sonKontrolGuncelle() {
        localStorage.setItem(LS_KEY, new Date().toISOString());
        badgeGuncelle(0); // badge'i hemen sıfırla
    }

    function badgeGuncelle(sayi) {
        if (sayi > 0) {
            badge.textContent = sayi > 99 ? '99+' : sayi;
            badge.style.display = 'block';
            widget.classList.remove('shake');
            void widget.offsetWidth; // reflow
            widget.classList.add('shake');
        } else {
            badge.style.display = 'none';
            badge.textContent = '';
        }
    }

    function bildirimKontrol() {
        var sonKontrol = getSonKontrol();
        var url = BILDIRIM_URL + '?son_kontrol=' + encodeURIComponent(sonKontrol);
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.timeout = 15000;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4 || xhr.status !== 200) return;
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    badgeGuncelle(res.yeni_sayisi || 0);
                }
            } catch(e) {}
        };
        xhr.send();
    }

    // Tıklanınca son_kontrol'ü güncelle (okundu işareti)
    widget.addEventListener('click', function() {
        sonKontrolGuncelle();
    });

    bildirimKontrol();
    setInterval(bildirimKontrol, POLLING_INTERVAL);
})();
</script>
