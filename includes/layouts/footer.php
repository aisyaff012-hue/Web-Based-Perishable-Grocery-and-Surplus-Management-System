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
     * The collapsed state is kept in a cookie so it survives
     * navigation — every page is a fresh request, so without it
     * the sidebar would reopen on each click.
     */
    function toggleSidebar() {
        var shell = document.querySelector('.shell');
        var collapsed = shell.classList.toggle('shell-collapsed');

        document.cookie = 'sidebar=' + (collapsed ? 'closed' : 'open')
            + ';path=/;max-age=31536000';
    }

    /*
     * Live notification polling.
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
         * Browsers block audio until the user interacts with the
         * page. Priming the element on the first interaction lets
         * later plays go through.
         */
        function unlockAudio() {
            sound.volume = 0.5;

            sound.play().then(function () {
                sound.pause();
                sound.currentTime = 0;
            }).catch(function () {
                // Still locked; the next interaction will retry.
            });
        }

        ['click', 'keydown', 'mousemove'].forEach(function (type) {
            document.addEventListener(type, unlockAudio, { once: true });
        });

        function playChime() {
            sound.currentTime = 0;

            sound.play().catch(function () {
                // Blocked before any interaction; nothing to do.
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
            }, 6000);
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
                // A failed poll is not worth interrupting the user.
            }
        }

        setInterval(checkNotifications, pollInterval);
    })();
</script>

</body>
</html>