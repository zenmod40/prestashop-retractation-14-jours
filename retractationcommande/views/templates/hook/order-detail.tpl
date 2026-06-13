{**
 * Encart rétractation sur la page de détail de commande (hook displayOrderDetail).
 * Le badge de statut et le bouton peuvent coexister : une rétractation
 * partielle laisse les quantités restantes rétractables pendant la fenêtre légale.
 *}
{if $retractation_existing || $retractation_eligible}
  <div class="retractation-orderdetail-box">
    <p class="retractation-status">{l s='Droit de rétractation' mod='retractationcommande'}</p>

    {if $retractation_existing}
      <p>
        <span class="retractation-status-badge">{$retractation_existing_label}</span>
        <br>
        <small>
          {l s='Demande' mod='retractationcommande'} {$retractation_existing.reference}
          {l s='du' mod='retractationcommande'} {dateFormat date=$retractation_existing.date_add full=0}
        </small>
      </p>
    {/if}

    {if $retractation_eligible}
      <p>
        {l s='Vous disposez d\'un délai légal de 14 jours pour vous rétracter' mod='retractationcommande'}
        {if $retractation_deadline_text}({$retractation_deadline_text}){/if}.
      </p>
      <a href="#" class="retractation-btn"
         data-id-order="{$retractation_id_order|intval}"
         data-rtoken="{$retractation_token}">
        {l s='Se rétracter' mod='retractationcommande'}
      </a>
    {/if}
  </div>
{/if}
