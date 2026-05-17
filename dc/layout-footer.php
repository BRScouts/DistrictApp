</main>
<footer class="lt-footer">
    <div class="lt-footer-inner">
        <p class="mb-1 font-weight-bold">Irwell Valley Scout District</p>
        <p class="mb-0">A practical tool for submitting activities, sharing risk assessments and supporting safe programme delivery.</p>
    </div>
</footer>
<script>
(function () {
  var toggle = document.querySelector('[data-menu-toggle]');
  var nav = document.getElementById('dc-main-nav');
  if (!toggle || !nav) return;
  toggle.addEventListener('click', function () {
    var open = nav.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/gh/scoutstrap/scoutstrap@0.1.1/dist/js/bootstrap.min.js" integrity="sha384-vZA7fWbUdVwzQZlO+dkC65mKiaTlKyDvRFeWWT/+J8nBCX0A/OJE2YaFG+m4Zhv0" crossorigin="anonymous"></script>
</body>
</html>
