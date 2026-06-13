{**
 * Formulaire type de rétractation prérempli (annexe à l'article R221-1
 * du Code de la consommation), affiché en modal.
 * La rétractation peut être partielle : sélection des produits et quantités.
 *}
<button type="button" class="retractation-close" aria-label="{l s='Fermer' mod='retractationcommande'}">&times;</button>

<h3>{l s='Rétractation de la commande' mod='retractationcommande'} {$rc_order->reference}</h3>

{if $rc_deadline_text}
  <p class="retractation-deadline">
    {l s='Droit de rétractation exerçable' mod='retractationcommande'} {$rc_deadline_text}.
  </p>
{/if}

<div class="retractation-formulaire-type">
  <p>
    {l s='À l\'attention de' mod='retractationcommande'}
    <strong>{$rc_shop_name}</strong>{if $rc_shop_address} — {$rc_shop_address}{/if}
  </p>
  <p>
    {l s='Je vous notifie par la présente ma rétractation du contrat portant sur la vente du/des bien(s) sélectionné(s) ci-dessous :' mod='retractationcommande'}
  </p>
  <table>
    <tr>
      <td>{l s='Commande' mod='retractationcommande'}</td>
      <td>{$rc_order->reference} ({l s='commandée le' mod='retractationcommande'} {$rc_order_date})</td>
    </tr>
    {if $rc_delivery_date}
      <tr>
        <td>{l s='Reçue le' mod='retractationcommande'}</td>
        <td>{$rc_delivery_date}</td>
      </tr>
    {/if}
    <tr>
      <td>{l s='Nom du consommateur' mod='retractationcommande'}</td>
      <td>{$rc_customer->firstname} {$rc_customer->lastname}</td>
    </tr>
    <tr>
      <td>{l s='Adresse du consommateur' mod='retractationcommande'}</td>
      <td>
        {$rc_address->address1}{if $rc_address->address2} {$rc_address->address2}{/if},
        {$rc_address->postcode} {$rc_address->city}
      </td>
    </tr>
    <tr>
      <td>{l s='Email' mod='retractationcommande'}</td>
      <td>{$rc_customer->email}</td>
    </tr>
    <tr>
      <td>{l s='Date' mod='retractationcommande'}</td>
      <td>{$rc_today}</td>
    </tr>
  </table>
</div>

<form class="retractation-form" method="post" action="{$rc_ajax_url}">
  <input type="hidden" name="action" value="confirm">
  <input type="hidden" name="id_order" value="{$rc_order->id|intval}">
  <input type="hidden" name="rtoken" value="{$rc_token}">

  {* Cas simple : un seul produit en quantité 1 — pas de sélecteur. *}
  {assign var=rc_single_simple value=($rc_products|count == 1 && $rc_products[0].max_qty == 1)}

  <h4 class="retractation-products-title">
    {if $rc_products|count > 1}
      {l s='Produits concernés — ajustez les quantités si votre rétractation est partielle' mod='retractationcommande'}
    {else}
      {l s='Produit concerné' mod='retractationcommande'}
    {/if}
  </h4>
  <table class="retractation-products-table">
    <thead>
      <tr>
        <th>{l s='Produit' mod='retractationcommande'}</th>
        <th>{l s='Référence' mod='retractationcommande'}</th>
        <th class="retractation-qty-col">{l s='Quantité' mod='retractationcommande'}</th>
      </tr>
    </thead>
    <tbody>
      {foreach $rc_products as $product}
        <tr>
          <td>{$product.product_name}</td>
          <td>{if isset($product.product_reference) && $product.product_reference}{$product.product_reference}{else}-{/if}</td>
          <td class="retractation-qty-col">
            {if $rc_single_simple}
              <input type="hidden" name="returnQty[{$product.id_order_detail|intval}]" value="1">
              <span class="retractation-qty-fixed">1</span>
            {else}
              <select name="returnQty[{$product.id_order_detail|intval}]" class="retractation-qty" aria-label="{l s='Quantité à rétracter' mod='retractationcommande'}">
                {for $q=$product.max_qty to 0 step -1}
                  <option value="{$q}">{$q}{if $q == 0} — {l s='conserver' mod='retractationcommande'}{/if}</option>
                {/for}
              </select>
              <span class="retractation-qty-max">/ {$product.max_qty}</span>
            {/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  <div class="form-group">
    <label for="rc_message">
      {l s='Motif (facultatif — la loi ne vous oblige pas à motiver votre décision)' mod='retractationcommande'}
    </label>
    <textarea name="rc_message" id="rc_message" maxlength="2000" class="form-control"></textarea>
  </div>

  <div class="retractation-actions">
    <button type="button" class="btn btn-secondary retractation-cancel">
      {l s='Annuler' mod='retractationcommande'}
    </button>
    <button type="submit" class="btn btn-primary">
      {l s='Confirmer la rétractation' mod='retractationcommande'}
    </button>
  </div>

  <p class="retractation-legal">
    {if $rc_delivery_date}
      {l s='En confirmant, votre demande de rétractation est transmise à notre service client et un accusé de réception vous est adressé par email avec un exemplaire PDF (art. L221-21 du Code de la consommation). Après vérification de l\'éligibilité (délai légal, exclusions de l\'art. L221-28), la procédure de retour vous sera communiquée — merci de ne pas renvoyer les produits avant de l\'avoir reçue. Le remboursement intervient au plus tard 14 jours après récupération du bien ou réception de la preuve d\'expédition (frais de livraison inclus en cas de rétractation totale, au prorata sinon). Les frais de renvoi restent à votre charge.' mod='retractationcommande'}
    {else}
      {l s='En confirmant, votre demande de rétractation est transmise à notre service client et un accusé de réception vous est adressé par email avec un exemplaire PDF (art. L221-21 du Code de la consommation). Votre commande n\'ayant pas encore été expédiée, aucun retour de produit ne sera nécessaire : après vérification (exclusions de l\'art. L221-28), l\'expédition sera annulée et la totalité des sommes versées vous sera remboursée au plus tard 14 jours après votre demande, par le même moyen de paiement.' mod='retractationcommande'}
    {/if}
  </p>
</form>
