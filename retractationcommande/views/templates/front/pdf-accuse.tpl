{**
 * Accusé de réception de la rétractation — rendu en PDF via TCPDF
 * (HTML simple, styles inline : TCPDF ne supporte qu'un sous-ensemble CSS).
 * Toutes les chaînes passent par {l} : traduisibles via BO > Traductions.
 *}
{if $rc_logo_path}
  <table width="100%" cellpadding="0">
    <tr>
      <td width="40%"><img src="{$rc_logo_path}" height="40"></td>
      <td width="60%" style="text-align: right; font-size: 9pt; color: #777;">
        {$rc_request_date}
      </td>
    </tr>
  </table>
  <br><br>
{/if}

<h1 style="font-size: 16pt; color: #222;">{l s='Accusé de réception' mod='retractationcommande'}</h1>
<h2 style="font-size: 12pt; color: #555;">{l s='Demande de rétractation' mod='retractationcommande'} {$rc_request_ref}</h2>

<table width="100%" cellpadding="3" style="font-size: 9.5pt;">
  <tr>
    <td width="20%"><strong>{l s='Boutique' mod='retractationcommande'}</strong></td>
    <td width="80%">{$rc_shop_name}</td>
  </tr>
  {if $rc_shop_address}
    <tr>
      <td><strong>{l s='Adresse' mod='retractationcommande'}</strong></td>
      <td>{$rc_shop_address}</td>
    </tr>
  {/if}
  {if $rc_shop_email}
    <tr>
      <td><strong>{l s='Contact' mod='retractationcommande'}</strong></td>
      <td>{$rc_shop_email}</td>
    </tr>
  {/if}
</table>

{if $rc_return_address}
  <table width="100%" cellpadding="3" style="font-size: 9.5pt; margin-top: 6px; border-top: 1px solid #eee;">
    <tr>
      <td width="20%"><strong>{l s='Adresse de retour' mod='retractationcommande'}</strong></td>
      <td width="80%">{$rc_return_address|escape:'html':'UTF-8'|nl2br nofilter}</td>
    </tr>
  </table>
{/if}

<p style="font-size: 10pt;">
  {l s='Conformément à l\'article L221-21 du Code de la consommation, nous accusons réception de votre demande de rétractation' mod='retractationcommande'}
  <strong>{$rc_request_ref}</strong>
  {l s='déposée le' mod='retractationcommande'}
  <strong>{$rc_request_date}</strong>
  {l s='via notre site.' mod='retractationcommande'}
</p>

<h3 style="font-size: 11pt;">{l s='Contrat concerné' mod='retractationcommande'}</h3>
<table width="100%" cellpadding="4" style="font-size: 9.5pt;">
  <tr>
    <td width="35%"><strong>{l s='Commande' mod='retractationcommande'}</strong></td>
    <td width="65%">{$rc_order->reference} ({l s='commandée le' mod='retractationcommande'} {$rc_order_date})</td>
  </tr>
  {if $rc_delivery_date}
    <tr>
      <td><strong>{l s='Livrée le' mod='retractationcommande'}</strong></td>
      <td>{$rc_delivery_date}</td>
    </tr>
  {/if}
  <tr>
    <td><strong>{l s='Consommateur' mod='retractationcommande'}</strong></td>
    <td>{$rc_customer->firstname} {$rc_customer->lastname} — {$rc_customer->email}</td>
  </tr>
  {if isset($rc_address) && $rc_address->id}
    <tr>
      <td><strong>{l s='Adresse de livraison' mod='retractationcommande'}</strong></td>
      <td>{$rc_address->address1}{if $rc_address->address2} {$rc_address->address2}{/if}, {$rc_address->postcode} {$rc_address->city}</td>
    </tr>
  {/if}
</table>

<h3 style="font-size: 11pt;">
  {if $rc_products|count > 1}{l s='Biens concernés' mod='retractationcommande'}{else}{l s='Bien concerné' mod='retractationcommande'}{/if}
</h3>
<table width="100%" cellpadding="4" border="0.5" style="font-size: 9pt;">
  <tr style="background-color: #f0f0f0;">
    <td width="10%" align="center"><strong>{l s='Qté' mod='retractationcommande'}</strong></td>
    <td width="44%"><strong>{l s='Produit' mod='retractationcommande'}</strong></td>
    <td width="16%"><strong>{l s='Référence' mod='retractationcommande'}</strong></td>
    <td width="15%" align="right"><strong>{l s='Prix unit. TTC' mod='retractationcommande'}</strong></td>
    <td width="15%" align="right"><strong>{l s='Total TTC' mod='retractationcommande'}</strong></td>
  </tr>
  {foreach $rc_products as $product}
    <tr>
      <td align="center">{$product.product_quantity}</td>
      <td>{$product.product_name}</td>
      <td>{if $product.product_reference}{$product.product_reference}{else}-{/if}</td>
      <td align="right">{$product.unit_price_formatted}</td>
      <td align="right">{$product.total_formatted}</td>
    </tr>
  {/foreach}
  <tr>
    <td colspan="4" align="right"><strong>{l s='Sous-total produits TTC' mod='retractationcommande'}</strong></td>
    <td align="right"><strong>{$rc_products_total}</strong></td>
  </tr>
  {if $rc_is_full_order}
    <tr>
      <td colspan="4" align="right">{l s='Frais de livraison (remboursés — rétractation totale)' mod='retractationcommande'}</td>
      <td align="right">{$rc_shipping_total}</td>
    </tr>
  {/if}
  <tr style="background-color: #f0f0f0;">
    <td colspan="4" align="right"><strong>{l s='Montant à rembourser (estimation)' mod='retractationcommande'}</strong></td>
    <td align="right"><strong>{$rc_refund_total}</strong></td>
  </tr>
</table>
{if !$rc_is_full_order}
  <p style="font-size: 8.5pt; color: #555;">
    {l s='Rétractation partielle : les frais de livraison sont remboursés au prorata des biens renvoyés (art. L221-24 du Code de la consommation). Le montant définitif vous sera confirmé par notre service client.' mod='retractationcommande'}
  </p>
{/if}

{if $rc_message}
  <h3 style="font-size: 11pt;">{l s='Message du consommateur' mod='retractationcommande'}</h3>
  <p style="font-size: 9.5pt;">{$rc_message|nl2br}</p>
{/if}

<h3 style="font-size: 11pt;">{l s='Suite de la procédure' mod='retractationcommande'}</h3>
<p style="font-size: 9.5pt;">
  {if $rc_phase == 'delivered'}
    {l s='Votre demande va être vérifiée par notre service client (respect du délai légal, exclusions prévues à l\'article L221-28 du Code de la consommation). Si elle est conforme, la procédure de retour vous sera transmise par email. Merci de ne pas renvoyer le(s) produit(s) avant de l\'avoir reçue. Le remboursement interviendra au plus tard 14 jours après récupération du bien ou réception de la preuve d\'expédition, par le même moyen de paiement que celui utilisé lors de la commande (art. L221-24). Les frais directs de renvoi restent à votre charge (art. L221-23).' mod='retractationcommande'}
  {elseif $rc_phase == 'shipped'}
    {l s='Votre commande étant déjà expédiée au moment de votre demande, vous pouvez refuser le colis à sa présentation ou, si vous le recevez, suivre la procédure de retour que notre service client vous transmettra après vérification (exclusions prévues à l\'article L221-28 du Code de la consommation). Le remboursement interviendra au plus tard 14 jours après récupération du bien ou réception de la preuve de son renvoi, par le même moyen de paiement que celui utilisé lors de la commande (art. L221-24). Les frais directs de renvoi restent à votre charge (art. L221-23).' mod='retractationcommande'}
  {else}
    {l s='Votre commande n\'ayant pas encore été expédiée au moment de votre demande, aucun retour de produit ne sera nécessaire. Après vérification par notre service client (exclusions prévues à l\'article L221-28 du Code de la consommation), l\'expédition sera annulée et la totalité des sommes versées vous sera remboursée au plus tard 14 jours après votre demande, par le même moyen de paiement que celui utilisé lors de la commande (art. L221-24).' mod='retractationcommande'}
  {/if}
</p>

<p style="font-size: 9.5pt;">
  {l s='Ce document vaut accusé de réception de votre rétractation. Conservez-le — votre référence à rappeler dans tout échange :' mod='retractationcommande'}
  <strong>{$rc_request_ref}</strong>
</p>
