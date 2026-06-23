</main><!-- /.app-main -->

    <footer class="app-footer">
        <span>Group 7 — Transaction / Request Management Subsystem &nbsp;·&nbsp; Southland College</span>
        <span>Teacher Portal &nbsp;·&nbsp; Logged in as <strong><?php echo htmlspecialchars($_SESSION['t_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></span>
    </footer>

</div><!-- /.app-shell -->

<script>
// Live clock
(function tick(){
    const el = document.getElementById('liveClock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    setTimeout(tick, 1000);
})();

// Auto-dismiss alerts
document.querySelectorAll('.alert.alert-success,.alert.alert-info').forEach(el => {
    setTimeout(() => { el.style.transition='opacity .5s'; el.style.opacity='0'; setTimeout(()=>el.remove(),500); }, 5000);
});

// Auto-refresh dashboard stat cards (only runs on pages that have the cards)
function refreshStats() {
    var presentEl = document.getElementById('presentCount');
    if (!presentEl) return;                       // not the dashboard — skip silently

    fetch('dashboard_stats.php')
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data || data.error) return;
            var set = function (id, html) {
                var el = document.getElementById(id);
                if (el) el.innerHTML = html;
            };
            set('presentCount',  data.present);
            set('lateCount',     '+' + data.late + ' Late');
            set('absentTotal',   (data.absent + data.notMarked));
            set('absentDetail',  data.absent + ' absent, ' + data.notMarked + ' no record');
            set('markedToday',   data.markedToday + ' checked in today');
            set('totalStudents', data.totalStudents);
        })
        .catch(function (error) { console.error('Error refreshing stats:', error); });
}

// Poll every 3 seconds, and once immediately on load
setInterval(refreshStats, 3000);
refreshStats();
</script>
</body>
</html>