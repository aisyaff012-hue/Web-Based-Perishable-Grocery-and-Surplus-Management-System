</main>
    </div>
</div>

<div class="modal" id="logoutModal">
    <div class="modal-card">
        <h3>Log Out</h3>
        <p class="auth-subtitle">You will be returned to the login page. Any unsaved changes on this page will be lost.</p>

        <div class="form-actions">
            <a class="button" href="<?= BASE_URL ?>/auth/logout.php">Yes, log out</a>
            <button class="button button-outline" type="button" onclick="closeLogout()">Stay logged in</button>
        </div>
    </div>
</div>

<a class="toast" id="notifyToast" href="notifications.php">
    <span class="toast-icon">&#128276;</span>

    <span class="toast-body">
        <strong id="toastTitle">New notification</strong>
        <span id="toastText">You have a new alert.</span>
    </span>
</a>

<audio id="notifySound" preload="auto" src="<?= BASE_URL ?>/assets/sounds/notify.mp3"></audio>

<script>
    function askLogout() {
        document.getElementById('logoutModal').classList.add('modal-open');
    }

    function closeLogout() {
        document.getElementById('logoutModal').classList.remove('modal-open');
    }
        /*
     * Status collapse sidebar disimpan dalam cookie supaya ia
     * kekal walaupun pindah page — setiap page adalah request
     * baru, jadi tanpa cookie ni sidebar akan terbuka semula
     * setiap kali diklik.
     */
    function toggleSidebar() {
        var shell = document.querySelector('.shell');
        var collapsed = shell.classList.toggle('shell-collapsed');

        document.cookie = 'sidebar=' + (collapsed ? 'closed' : 'open')
            + ';path=/;max-age=31536000';
    }

    /*
     * Polling notifikasi secara live.
     */
    (function () {
        const pollUrl = '<?= BASE_URL ?>/api/notification-count.php';
        const pollInterval = 3000;

        const bell = document.querySelector('.bell');
        const toast = document.getElementById('notifyToast');
        const toastTitle = document.getElementById('toastTitle');
        const toastText = document.getElementById('toastText');
        const sound = document.getElementById('notifySound');

        let lastCount = <?= (int) $unreadCount ?>;
        let hideTimer = null;

        /*
         * Browser sekat bunyi (audio) sampai user buat interaksi
         * dengan page dulu. "Prime" elemen audio pada interaksi
         * pertama ni supaya play seterusnya boleh jalan.
         */
        function unlockAudio() {
            sound.volume = 0.5;

            sound.play().then(function () {
                sound.pause();
                sound.currentTime = 0;
            }).catch(function () {
                // Masih locked; interaksi seterusnya akan cuba lagi.
            });
        }

        ['click', 'keydown', 'mousemove'].forEach(function (type) {
            document.addEventListener(type, unlockAudio, { once: true });
        });

        function playChime() {
            sound.currentTime = 0;

            sound.play().catch(function () {
                // Disekat sebelum ada apa-apa interaksi; tiada apa
                // boleh buat, abaikan je.
            });
        }

        function showToast(title, message, difference) {
            toastTitle.textContent = title || 'New notification';

            toastText.textContent = message
                || (difference === 1
                    ? 'You have 1 new notification.'
                    : 'You have ' + difference + ' new notifications.');

            toast.classList.add('toast-open');

            clearTimeout(hideTimer);

            hideTimer = setTimeout(function () {
                toast.classList.remove('toast-open');
            }, 8000);
        }

        function updateBadge(count) {
            if (!bell) {
                return;
            }

            const existing = bell.querySelector('.bell-count');

            if (count > 0) {
                bell.classList.add('bell-active');

                if (existing) {
                    existing.textContent = count > 9 ? '9+' : count;
                } else {
                    const badge = document.createElement('span');
                    badge.className = 'bell-count';
                    badge.textContent = count > 9 ? '9+' : count;
                    bell.appendChild(badge);
                }
            } else {
                bell.classList.remove('bell-active');

                if (existing) {
                    existing.remove();
                }
            }
        }

        async function checkNotifications() {
            try {
                const response = await fetch(pollUrl, {
                    cache: 'no-store'
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                const count = parseInt(data.unread, 10);

                if (isNaN(count)) {
                    return;
                }

                if (count > lastCount) {
                    playChime();
                    showToast(data.title, data.message, count - lastCount);
                }

                updateBadge(count);
                lastCount = count;
            } catch (error) {
                // Satu poll gagal tak berbaloi nak ganggu user —
                // abaikan je, cuba lagi pada poll seterusnya.
            }
        }

        setInterval(checkNotifications, pollInterval);
    })();
</script>

</body>
</html>