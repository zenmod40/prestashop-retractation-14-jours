{**
 * Page "Exercer mon droit de rétractation" (lien footer).
 * Étape 1 : identification (commandes du client connecté, ou email + référence en invité).
 * Étape 2 : la modal de confirmation (formulaire type), via le JS du module.
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {l s='Exercer mon droit de rétractation' mod='retractationcommande'}
{/block}

{block name='page_content'}
  <div class="retractation-page">
    <p>
      {l s='Conformément aux articles L221-18 et suivants du Code de la consommation, vous disposez d\'un délai de' mod='retractationcommande'}
      <strong>{$rc_delay_days} {l s='jours' mod='retractationcommande'}</strong>
      {l s='à compter du lendemain de la livraison pour vous rétracter, sans avoir à motiver votre décision. Si ce délai expire un samedi, un dimanche ou un jour férié, il est prolongé jusqu\'au premier jour ouvrable suivant.' mod='retractationcommande'}
    </p>

    {if $rc_is_logged}
      {* ---------- Client connecté : commandes dans le délai ou suivies ---------- *}
      <h2 class="h3">{l s='Vos commandes éligibles à la rétractation' mod='retractationcommande'}</h2>
      {if $rc_orders|count}
        <table class="table table-striped retractation-orders-table">
          <thead>
            <tr>
              <th>{l s='Référence' mod='retractationcommande'}</th>
              <th>{l s='Date' mod='retractationcommande'}</th>
              <th>{l s='Total' mod='retractationcommande'}</th>
              <th>{l s='Rétractation' mod='retractationcommande'}</th>
            </tr>
          </thead>
          <tbody>
            {foreach $rc_orders as $o}
              <tr>
                <td>{$o.reference}</td>
                <td>{$o.date}</td>
                <td>{$o.total}</td>
                <td>
                  {if $o.status_label}
                    <span class="retractation-status-badge">{$o.status_label}</span>
                  {/if}
                  {if $o.eligible}
                    <a href="#" class="retractation-btn"
                       data-id-order="{$o.id_order|intval}"
                       data-rtoken="{$o.token}">
                      {l s='Se rétracter' mod='retractationcommande'}
                    </a>
                    {if $o.deadline_text}
                      <div class="retractation-deadline-hint">{$o.deadline_text}</div>
                    {/if}
                  {/if}
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      {else}
        <p class="alert alert-info">
          {l s='Aucune de vos commandes n\'est actuellement éligible à la rétractation (délai légal expiré ou commandes non concernées).' mod='retractationcommande'}
        </p>
      {/if}
    {else}
      {* ---------- Parcours invité : email + référence ---------- *}
      <h2 class="h3">{l s='Retrouvez votre commande' mod='retractationcommande'}</h2>
      <p>
        {l s='Saisissez l\'adresse email utilisée lors de la commande et la référence de la commande (présente sur votre email de confirmation).' mod='retractationcommande'}
        <br>
        <a href="{$rc_login_url}">{l s='Vous avez un compte ? Connectez-vous pour retrouver toutes vos commandes.' mod='retractationcommande'}</a>
      </p>

      {if $rc_guest_error}
        <p class="alert alert-danger">{$rc_guest_error}</p>
      {/if}

      <form method="post" action="" class="retractation-guest-form">
        <div class="form-group row">
          <label class="col-md-3 form-control-label" for="guest_email">{l s='Adresse email' mod='retractationcommande'}</label>
          <div class="col-md-5">
            <input type="email" class="form-control" name="guest_email" id="guest_email" required value="{$rc_guest_email|escape:'html'}">
          </div>
        </div>
        <div class="form-group row">
          <label class="col-md-3 form-control-label" for="guest_reference">{l s='Référence de commande' mod='retractationcommande'}</label>
          <div class="col-md-5">
            <input type="text" class="form-control" name="guest_reference" id="guest_reference" required
                   placeholder="{l s='ex. XKBKNABJK' mod='retractationcommande'}" value="{$rc_guest_reference|escape:'html'}">
          </div>
        </div>
        <div class="form-group row">
          <div class="col-md-5 offset-md-3">
            <button type="submit" name="submitGuestSearch" class="btn btn-primary">
              {l s='Rechercher ma commande' mod='retractationcommande'}
            </button>
          </div>
        </div>
      </form>

      {if $rc_guest_order}
        <div class="retractation-guest-result">
          <h3 class="h4">{l s='Commande' mod='retractationcommande'} {$rc_guest_order.reference} — {$rc_guest_order.date}</h3>
          {if $rc_guest_order.status_label}
            <p><span class="retractation-status-badge">{$rc_guest_order.status_label}</span></p>
          {/if}
          {if $rc_guest_order.eligible}
            {if $rc_guest_order.deadline_text}
              <p class="retractation-deadline">{l s='Droit de rétractation exerçable' mod='retractationcommande'} {$rc_guest_order.deadline_text}.</p>
            {/if}
            <a href="#" class="btn btn-primary retractation-btn"
               data-id-order="{$rc_guest_order.id_order|intval}"
               data-rtoken="{$rc_guest_order.token}">
              {l s='Se rétracter' mod='retractationcommande'}
            </a>
          {elseif !$rc_guest_order.status_label}
            <p class="alert alert-warning">
              {l s='Cette commande n\'est pas (ou plus) éligible à la rétractation en ligne : délai légal expiré, demande déjà déposée ou commande non concernée. Pour toute question, contactez notre service client.' mod='retractationcommande'}
            </p>
          {/if}
        </div>
      {/if}
    {/if}

    <hr>
    <p class="retractation-legal">
      {l s='Le droit de rétractation ne s\'applique pas à certains contrats (art. L221-28 C. conso : biens sur mesure ou personnalisés, biens périssables, biens descellés pour raison d\'hygiène, etc.). Après dépôt, votre demande est vérifiée par notre service client qui vous transmet la procédure de retour. Le remboursement intervient au plus tard 14 jours après récupération du bien ou réception de la preuve d\'expédition, par le même moyen de paiement. Les frais de renvoi restent à votre charge.' mod='retractationcommande'}
    </p>
  </div>
{/block}
