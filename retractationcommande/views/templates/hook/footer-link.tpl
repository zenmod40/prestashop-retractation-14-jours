{**
 * Lien "Exercer mon droit de rétractation" — pied de page de toutes les pages
 * (fonctionnalité visible et facilement accessible, ordonnance n°2026-2).
 *
 * Le lien est inséré à la fin de la/des liste(s) de liens CMS du footer
 * (ps_linklist : Livraison, Mentions légales, …) pour s'intégrer au thème.
 * Si aucune liste CMS n'est trouvée, le bloc autonome ci-dessous sert de
 * solution de repli.
 *}
<div class="retractation-footer-link" id="retractation-footer-fallback" style="display:none;">
  <a href="{$retractation_link_url}" title="{$retractation_link_label|escape:'html'}">
    {$retractation_link_label}
  </a>
</div>
<script>
(function () {
  var url = {$retractation_link_url|json_encode nofilter};
  var label = {$retractation_link_label|json_encode nofilter};

  function insert() {
    // Listes du footer contenant des liens CMS (thème classic : ul#footer_sub_menu_X)
    var lists = [];
    document.querySelectorAll('.footer-container a.cms-page-link, footer a.cms-page-link').forEach(function (a) {
      var ul = a.closest('ul');
      if (ul && lists.indexOf(ul) === -1) {
        lists.push(ul);
      }
    });

    if (!lists.length) {
      var fallback = document.getElementById('retractation-footer-fallback');
      if (fallback) {
        fallback.style.display = '';
      }
      return;
    }

    lists.forEach(function (ul) {
      if (ul.querySelector('.retractation-cms-link')) {
        return;
      }
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.className = 'cms-page-link retractation-cms-link';
      a.href = url;
      a.title = label;
      a.textContent = label;
      li.appendChild(a);
      ul.appendChild(li);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', insert);
  } else {
    insert();
  }
})();
</script>
