/**
 * Rétractation Commande — front
 * - Injecte le bouton "Se rétracter" et le badge de statut sur "Mes commandes"
 *   (un seul bouton par ligne de commande, dans la cellule d'actions).
 * - Gère la modal (formulaire type prérempli, sélection produits/quantités).
 * - Masque le formulaire de retour natif sur le détail de commande (option BO).
 */
(function () {
  'use strict';

  if (typeof retractationConfig === 'undefined') {
    return;
  }

  var cfg = retractationConfig;

  /* ------------------------------------------------------------------ */
  /* Injection sur la page "Mes commandes"                               */
  /* ------------------------------------------------------------------ */

  function extractOrderId(href) {
    var match = /[?&]id_order=(\d+)/.exec(href || '');
    return match ? parseInt(match[1], 10) : null;
  }

  function buildButton(idOrder, token) {
    var btn = document.createElement('a');
    btn.href = '#';
    btn.className = 'retractation-btn';
    btn.textContent = cfg.labels.button;
    btn.setAttribute('data-id-order', idOrder);
    btn.setAttribute('data-rtoken', token);
    return btn;
  }

  function buildBadge(label) {
    var badge = document.createElement('span');
    badge.className = 'retractation-status-badge';
    badge.textContent = label;
    return badge;
  }

  function injectHistoryButtons() {
    var hasOrders = cfg.orders && Object.keys(cfg.orders).length;
    var hasStatuses = cfg.statuses && Object.keys(cfg.statuses).length;
    if (!hasOrders && !hasStatuses) {
      return;
    }

    // Une commande = une ligne (tr en desktop, bloc .order en mobile).
    // Chaque ligne contient plusieurs liens portant id_order (détails,
    // facture, recommander…) : on ne traite chaque ligne qu'une seule fois
    // et on insère dans la cellule d'actions uniquement.
    document.querySelectorAll('a[href*="id_order="]').forEach(function (link) {
      var idOrder = extractOrderId(link.getAttribute('href'));
      if (!idOrder) {
        return;
      }
      var info = cfg.orders ? cfg.orders[idOrder] : null;
      var status = cfg.statuses ? cfg.statuses[idOrder] : null;
      if (!info && !status) {
        return;
      }

      var row = link.closest('tr') || link.closest('.order') || link.closest('article');
      if (!row || row.getAttribute('data-retractation-done')) {
        return;
      }
      row.setAttribute('data-retractation-done', '1');

      var container = row.querySelector('td.order-actions, .order-actions');
      if (!container) {
        container = document.createElement('div');
        container.className = 'retractation-inline-actions';
        row.appendChild(container);
      }

      var wrap = document.createElement('div');
      wrap.className = 'retractation-cell';
      if (status) {
        wrap.appendChild(buildBadge(status));
      }
      if (info) {
        wrap.appendChild(buildButton(idOrder, info.token));
      }
      container.appendChild(wrap);
    });
  }

  /* ------------------------------------------------------------------ */
  /* Masquage du formulaire de retour natif (détail de commande)         */
  /* ------------------------------------------------------------------ */

  function hideNativeReturnForm() {
    if (!cfg.hideNativeForm) {
      return;
    }
    // Thème classic : bouton name="submitReturnMerchandise" + cases à cocher
    // du tableau produits. On masque le bloc de soumission et les cases.
    var submit = document.querySelector('[name="submitReturnMerchandise"]');
    if (submit) {
      var section = submit.closest('section, .order-message-form, form > div');
      (section || submit).style.display = 'none';
    }
    document.querySelectorAll('#order-products input[type="checkbox"], .order-return-checkbox')
      .forEach(function (cb) {
        var cell = cb.closest('td, th, div');
        (cell || cb).style.display = 'none';
      });
  }

  /* ------------------------------------------------------------------ */
  /* Modal                                                               */
  /* ------------------------------------------------------------------ */

  var overlay = null;

  function closeModal() {
    if (overlay) {
      overlay.remove();
      overlay = null;
      document.body.classList.remove('retractation-modal-open');
    }
  }

  function openModal(html) {
    closeModal();
    overlay = document.createElement('div');
    overlay.className = 'retractation-overlay';
    overlay.innerHTML = '<div class="retractation-modal" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(overlay);
    document.body.classList.add('retractation-modal-open');

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        closeModal();
      }
    });
    overlay.querySelectorAll('.retractation-close, .retractation-cancel').forEach(function (btn) {
      btn.addEventListener('click', closeModal);
    });
    var form = overlay.querySelector('.retractation-form');
    if (form) {
      form.addEventListener('submit', onConfirm);
      // Bouton "Confirmer" grisé tant qu'aucune quantité n'est sélectionnée.
      form.addEventListener('change', function () { updateSubmitState(form); });
      updateSubmitState(form);
    }
  }

  function updateSubmitState(form) {
    var total = 0;
    form.querySelectorAll('[name^="returnQty"]').forEach(function (el) {
      total += parseInt(el.value, 10) || 0;
    });
    var submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = total === 0;
      submitBtn.classList.toggle('disabled', total === 0);
    }
  }

  function showLoading() {
    openModal('<div class="retractation-loading">' + cfg.labels.loading + '</div>');
  }

  function showError(message) {
    openModal(
      '<button type="button" class="retractation-close" aria-label="Fermer">&times;</button>' +
      '<div class="alert alert-danger retractation-alert">' + message + '</div>'
    );
  }

  function fetchForm(idOrder, rtoken) {
    showLoading();
    var url = cfg.ajaxUrl + (cfg.ajaxUrl.indexOf('?') === -1 ? '?' : '&') +
      'action=form&ajax=1&id_order=' + encodeURIComponent(idOrder) +
      '&rtoken=' + encodeURIComponent(rtoken);

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          openModal(data.html);
        } else {
          showError(data.message || cfg.labels.error);
        }
      })
      .catch(function () { showError(cfg.labels.error); });
  }

  function onConfirm(e) {
    e.preventDefault();
    var form = e.target;
    var submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.classList.add('disabled');
    }

    var formData = new FormData(form);
    formData.append('ajax', '1');
    var idOrder = formData.get('id_order');

    fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          var pdfLink = data.pdf_url
            ? '<p class="retractation-pdf-link"><a class="btn btn-secondary" href="' + data.pdf_url + '">' +
              cfg.labels.download_pdf + '</a></p>'
            : '';
          openModal(
            '<button type="button" class="retractation-close" aria-label="Fermer">&times;</button>' +
            '<div class="retractation-success">' +
            '<h3>' + cfg.labels.success_title + '</h3>' +
            '<p>' + data.message + '</p>' + pdfLink +
            '</div>'
          );
          // Remplace les boutons de cette commande par le badge de statut.
          document.querySelectorAll('.retractation-btn[data-id-order="' + idOrder + '"]')
            .forEach(function (b) {
              b.replaceWith(buildBadge(cfg.labels.pending));
            });
        } else {
          showError(data.message || cfg.labels.error);
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('disabled');
          }
        }
      })
      .catch(function () { showError(cfg.labels.error); });
  }

  /* ------------------------------------------------------------------ */
  /* Bind                                                                */
  /* ------------------------------------------------------------------ */

  function init() {
    injectHistoryButtons();
    hideNativeReturnForm();
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.retractation-btn');
      if (!btn) {
        return;
      }
      e.preventDefault();
      fetchForm(btn.getAttribute('data-id-order'), btn.getAttribute('data-rtoken'));
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeModal();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
