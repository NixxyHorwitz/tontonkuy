  </main>

  <nav class="bottom-nav">
    <a href="/home" class="nav-item <?= ($activePage??'')==='home'?'active':'' ?>">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Beranda
    </a>
    <a href="/videos" class="nav-item <?= ($activePage??'')==='videos'?'active':'' ?>">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
      Tonton
    </a>
    <a href="/checkin" class="nav-item nav-item--center <?= ($activePage??'')==='checkin'?'active':'' ?>">
      <div class="nav-center-btn">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
      </div>
    </a>
    <a href="/referral" class="nav-item <?= ($activePage??'')==='referral'?'active':'' ?>">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Referral
    </a>
    <a href="/profile" class="nav-item <?= ($activePage??'')==='profile'?'active':'' ?>">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profil
    </a>
  </nav>
</div>
<script src="/assets/js/toast.js"></script>
</body>
</html>
