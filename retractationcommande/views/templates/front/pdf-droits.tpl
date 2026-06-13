{**
 * Page 2 de l'accusé de réception — rappel des droits du consommateur.
 * (Rend aussi le PDF multi-pages : Apple Mail l'affiche alors en icône de
 * pièce jointe au lieu de le prévisualiser dans le corps du message.)
 *}
<h1 style="font-size: 14pt; color: #222;">{l s='Rappel de vos droits — droit de rétractation' mod='retractationcommande'}</h1>

<h3 style="font-size: 11pt;">{l s='Le délai' mod='retractationcommande'}</h3>
<p style="font-size: 9.5pt;">
  {l s='Vous disposez d\'un délai de 14 jours pour vous rétracter, sans avoir à motiver votre décision (art. L221-18 du Code de la consommation). Le décompte commence le lendemain de la livraison du bien (ou de la conclusion du contrat pour une prestation de services). Si le délai expire un samedi, un dimanche ou un jour férié, il est prolongé jusqu\'au premier jour ouvrable suivant.' mod='retractationcommande'}
</p>

<h3 style="font-size: 11pt;">{l s='Le remboursement' mod='retractationcommande'}</h3>
<p style="font-size: 9.5pt;">
  {l s='Le remboursement porte sur la totalité des sommes versées, y compris les frais de livraison standard (au prorata en cas de rétractation partielle). Il intervient au plus tard 14 jours après récupération du bien ou réception de la preuve de son expédition, par le même moyen de paiement que celui utilisé lors de la commande (art. L221-24). Les frais directs de renvoi du bien restent à votre charge (art. L221-23).' mod='retractationcommande'}
</p>

<h3 style="font-size: 11pt;">{l s='Les exceptions (art. L221-28)' mod='retractationcommande'}</h3>
<p style="font-size: 9.5pt;">
  {l s='Le droit de rétractation ne peut pas être exercé notamment pour :' mod='retractationcommande'}
</p>
<ul style="font-size: 9.5pt;">
  <li>{l s='les biens confectionnés selon vos spécifications ou nettement personnalisés ;' mod='retractationcommande'}</li>
  <li>{l s='les biens susceptibles de se détériorer ou de se périmer rapidement ;' mod='retractationcommande'}</li>
  <li>{l s='les biens descellés après la livraison qui ne peuvent être renvoyés pour des raisons d\'hygiène ou de protection de la santé ;' mod='retractationcommande'}</li>
  <li>{l s='les enregistrements audio/vidéo ou logiciels descellés après la livraison ;' mod='retractationcommande'}</li>
  <li>{l s='les contenus numériques fournis sans support matériel dont l\'exécution a commencé avec votre accord ;' mod='retractationcommande'}</li>
  <li>{l s='les biens indissociablement mélangés à d\'autres articles après la livraison.' mod='retractationcommande'}</li>
</ul>

<h3 style="font-size: 11pt;">{l s='La suite de votre demande' mod='retractationcommande'}</h3>
<p style="font-size: 9.5pt;">
  {if $rc_is_delivered}
    {l s='Notre service client vérifie votre demande puis vous adresse la procédure de retour par email. Merci de ne pas renvoyer le(s) produit(s) avant de l\'avoir reçue, et de retourner les biens complets, dans leur état d\'origine.' mod='retractationcommande'}
  {else}
    {l s='Commande non expédiée : aucun retour de produit ne sera nécessaire. Notre service client vérifie votre demande, annule l\'expédition et procède au remboursement.' mod='retractationcommande'}
  {/if}
</p>

<h3 style="font-size: 11pt;">{l s='En cas de litige' mod='retractationcommande'}</h3>
<p style="font-size: 9.5pt;">
  {l s='Si vous estimez que votre demande n\'a pas été traitée conformément à la réglementation, vous pouvez contacter notre service client, puis recourir gratuitement à un médiateur de la consommation (art. L612-1 du Code de la consommation).' mod='retractationcommande'}
</p>
