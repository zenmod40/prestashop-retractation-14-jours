{**
 * Vue détaillée d'une demande de rétractation (BO SAV).
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-undo"></i>
    Demande de rétractation <strong>{$rc_request->reference}</strong> (ID interne #{$rc_request->id|intval})
    {if $rc_request->status == 'pending'}<span class="badge badge-warning">À vérifier</span>
    {elseif $rc_request->status == 'accepted'}<span class="badge badge-info">Conforme — procédure envoyée</span>
    {elseif $rc_request->status == 'refused'}<span class="badge badge-danger">Refusée</span>
    {elseif $rc_request->status == 'refunded'}<span class="badge badge-success">Remboursée</span>{/if}
  </div>

  <div class="row">
    <div class="col-lg-6">
      <h4>Demande</h4>
      <table class="table">
        <tr><td><strong>Déposée le</strong></td><td>{$rc_request->date_add}</td></tr>
        <tr>
          <td><strong>Phase logistique (au dépôt)</strong></td>
          <td>
            {if $rc_request->shipping_phase == 'delivered'}<span class="badge badge-success">Livrée</span>
            {elseif $rc_request->shipping_phase == 'shipped'}<span class="badge badge-info">Expédiée (en cours d'acheminement)</span>
            {else}<span class="badge badge-warning">Non expédiée</span>{/if}
          </td>
        </tr>
        <tr><td><strong>Date de livraison constatée</strong></td><td>{if $rc_request->delivery_date}{$rc_request->delivery_date}{else}<em>non livrée au moment de la demande</em>{/if}</td></tr>
        <tr>
          <td><strong>Date limite légale</strong></td>
          <td>
            {if $rc_request->legal_deadline}
              {$rc_request->legal_deadline}
              <br><small class="text-muted">14 jours à compter du lendemain de la livraison, prolongée au 1er jour ouvrable si échéance un samedi, dimanche ou jour férié (art. L221-18 C. conso).</small>
            {else}
              <em>non applicable (commande non livrée — droit ouvert dès la conclusion du contrat)</em>
            {/if}
          </td>
        </tr>
        <tr>
          <td><strong>Déposée dans les délais</strong></td>
          <td>{if $rc_request->within_deadline}<span class="badge badge-success">Oui</span>{else}<span class="badge badge-danger">Non</span>{/if}</td>
        </tr>
        {if $rc_request->message}
          <tr><td><strong>Motif du client</strong></td><td>{$rc_request->message|nl2br nofilter}</td></tr>
        {/if}
        {if $rc_request->refusal_reason}
          <tr><td><strong>Motif du refus</strong></td><td>{$rc_request->refusal_reason|nl2br nofilter}</td></tr>
        {/if}
      </table>
    </div>

    <div class="col-lg-6">
      <h4>Commande & client</h4>
      <table class="table">
        {if $rc_order}
          <tr>
            <td><strong>Commande</strong></td>
            <td><a href="{$rc_order_link}" target="_blank">{$rc_order->reference}</a> ({$rc_order->date_add})</td>
          </tr>
          <tr><td><strong>Total payé</strong></td><td>{$rc_order->total_paid_tax_incl}</td></tr>
        {/if}
        {if $rc_customer}
          <tr><td><strong>Client</strong></td><td>{$rc_customer->firstname} {$rc_customer->lastname} ({$rc_customer->email})</td></tr>
        {/if}
        {if $rc_order_return_link}
          <tr>
            <td><strong>Retour natif lié</strong></td>
            <td><a href="{$rc_order_return_link}" target="_blank">Retour #{$rc_request->id_order_return|intval}</a> (SAV &gt; Retours produits)</td>
          </tr>
        {/if}
        {if $rc_pdf_available}
          <tr>
            <td><strong>Accusé de réception</strong></td>
            <td>
              <a class="btn btn-default btn-xs" href="{$rc_current_index}&downloadRetractationPdf=1">
                <i class="icon-file-text"></i> Télécharger le PDF
              </a>
            </td>
          </tr>
        {/if}
      </table>
    </div>
  </div>

  <h4>Produits de la commande</h4>
  <table class="table">
    <thead>
      <tr>
        <th>Qté commandée</th>
        <th>Qté rétractée</th>
        <th>Produit</th>
        <th>Référence</th>
        <th>Exclusion configurée (L221-28)</th>
      </tr>
    </thead>
    <tbody>
      {foreach $rc_products as $product}
        <tr>
          <td>{$product.product_quantity}</td>
          <td>
            {assign var=rqty value=$rc_requested_qty[$product.id_order_detail]|default:0}
            {if $rqty}
              <strong>{$rqty}</strong>
            {else}
              <span class="text-muted">—</span>
            {/if}
          </td>
          <td>{$product.product_name}</td>
          <td>{$product.product_reference|default:'-'}</td>
          <td>
            {if in_array($product.id_order_detail, $rc_excluded_ids)}
              <span class="badge badge-danger">Potentiellement exclu — à vérifier</span>
            {else}
              <span class="badge badge-success">OK</span>
            {/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>

  <div class="alert alert-info" style="margin-top: 20px;">
    <strong>Points de contrôle SAV :</strong> respect du délai de 14 jours, exclusions légales
    (sur mesure / personnalisé, périssable, descellé pour hygiène, contenu numérique…
    art. L221-28 C. conso), produits déjà retournés. Le remboursement intégral (produit + livraison
    standard) doit intervenir au plus tard 14 jours après récupération du bien ou preuve d'expédition
    (art. L221-24).
  </div>

  {if $rc_request->status == 'pending'}
    <div class="row">
      <div class="col-lg-6">
        <form method="post" action="{$rc_current_index}">
          <button type="submit" name="submitAcceptRetractation" class="btn btn-success">
            <i class="icon-check"></i>
            {if $rc_request->shipping_phase == 'pending'}
              Conforme — annulation avant expédition (aucun retour)
            {else}
              Conforme — envoyer la procédure de retour
            {/if}
          </button>
          {if $rc_request->shipping_phase == 'pending'}
            <p class="help-block">Commande non expédiée au moment de la demande : le client sera informé de l'annulation, aucun retour de produit ne sera demandé. Pensez à bloquer l'expédition et à rembourser depuis la fiche commande.</p>
          {elseif $rc_request->shipping_phase == 'shipped'}
            <p class="help-block">Commande en cours d'acheminement au moment de la demande : le client peut refuser le colis ou le renvoyer. La procédure de retour lui sera envoyée. Le délai de 14 jours ne démarre qu'à la livraison.</p>
          {/if}
        </form>
      </div>
      <div class="col-lg-6">
        <form method="post" action="{$rc_current_index}">
          <div class="form-group">
            <label for="refusal_reason">Motif du refus (communiqué au client)</label>
            <textarea name="refusal_reason" id="refusal_reason" class="form-control" rows="3"></textarea>
          </div>
          <button type="submit" name="submitRefuseRetractation" class="btn btn-danger"
                  onclick="return confirm('Refuser cette demande de rétractation ?');">
            <i class="icon-remove"></i> Refuser la demande
          </button>
        </form>
      </div>
    </div>
  {elseif $rc_request->status == 'accepted'}
    <form method="post" action="{$rc_current_index}">
      <button type="submit" name="submitRefundRetractation" class="btn btn-success"
              onclick="return confirm('Confirmer : produit reçu, contrôlé et remboursé ?');">
        <i class="icon-money"></i> Produit reçu et contrôlé — marquer comme remboursée
      </button>
      <p class="help-block">Le remboursement réel s'effectue depuis la fiche commande (remboursement standard natif).</p>
    </form>
  {/if}
</div>
