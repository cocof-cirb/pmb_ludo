<?php
// +-------------------------------------------------+
// � 2002-2004 PMB Services / www.sigb.net pmb@sigb.net et contributeurs (voir www.sigb.net)
// +-------------------------------------------------+
// $Id: prolongation.inc.php,v 1.28 2015-04-03 11:16:23 jpermanne Exp $

if (stristr($_SERVER['REQUEST_URI'], ".inc.php")) die("no access");

// script de prolongation d'un pr�t

/* on dispose en principe de :
$form_cb -> code barre de l'exemplaire concern�
$cb_doc -> code barre de l'exemplaire
$date_retour -> la nouvelle date de retour (format MySQL)
$date_retour_lib -> nouvelle date de retour au format dd mm yyyy
*/  

require_once("$class_path/pret.class.php");
require_once("$class_path/serial_display.class.php");
require_once("$class_path/serials.class.php");

function prolonger($id_prolong) {
	global $id_empr,$date_retour, $date_retour_lib, $form_cb, $cb_doc, $confirm;
	global $dbh, $alert_sound_list, $msg;
	global $pmb_pret_restriction_prolongation, $pmb_pret_nombre_prolongation, $force_prolongation, $bloc_prolongation;
	global $deflt2docs_location,$pmb_location_reservation;
	
		$prolongation=TRUE;	
	
		//R�cup�ration des ids de notices et de bulletin par rapport � l'id de l'exemplaire plac� en param�tre 	
		$query = "select expl_cb, expl_notice, expl_bulletin from exemplaires where expl_id='$id_prolong' limit 1";
		$result = pmb_mysql_query($query, $dbh);
	
		if(pmb_mysql_num_rows($result)) {
			$retour = pmb_mysql_fetch_object($result);
			
			$cb_doc=$retour->expl_cb;
			//R�cup�ration du nombre de prolongations effectu�es pour l'exemplaire
			$query_prolong = "select cpt_prolongation, retour_initial,  pret_date from pret where pret_idexpl=".$id_prolong." limit 1";
			$result_prolong = pmb_mysql_query($query_prolong, $dbh);
			$data = pmb_mysql_fetch_array($result_prolong);
			$cpt_prolongation = $data['cpt_prolongation']; 
			$retour_initial =  $data['retour_initial'];
			$pret_date =  $data['pret_date'];
			$pret_day=explode(" ",$pret_date);
			if($pret_day[0] != today())	$cpt_prolongation++;			
			if ($force_prolongation!=1) {
				//Rechercher s'il subsiste une r�servation � traiter sur le bulletin ou la notice
				$query_resa = "select count(1) from resa where resa_idnotice=".$retour->expl_notice." and resa_idbulletin=".$retour->expl_bulletin." and (resa_cb='' or resa_cb='$cb_doc')";
				
				if($pmb_location_reservation ) {	
					$query_resa = "select count(1) from resa,empr,resa_loc 
					where resa_idnotice=".$retour->expl_notice." and resa_idbulletin=".$retour->expl_bulletin." and (resa_cb='' or resa_cb='$cb_doc')
					and resa_idempr=id_empr
					and empr_location=resa_emprloc and resa_loc='".$deflt2docs_location."' 
					";
				}	
				$result_resa = pmb_mysql_query($query_resa, $dbh);
				$has_resa = pmb_mysql_result($result_resa,0,0);
				if (!$has_resa) {
					if ($pmb_pret_restriction_prolongation>0) {
						//limitation simple du pr�t
						if($pmb_pret_restriction_prolongation==1) {
							$pret_nombre_prolongation=$pmb_pret_nombre_prolongation;
							$forcage_prolongation=1;
						} else {
							//Initialisation des quotas pour nombre de prolongations
							$qt = new quota("PROLONG_NMBR_QUOTA");
							//Tableau de passage des param�tres
							$struct["READER"] = $id_empr;
							$struct["EXPL"] = $id_prolong;
				
							$pret_nombre_prolongation=$qt -> get_quota_value($struct);		
				
							$forcage_prolongation=$qt -> get_force_value($struct);
						}
						if($cpt_prolongation>$pret_nombre_prolongation) {
							$prolongation=FALSE;
						}
					}	
				} else {
					$prolongation=FALSE;
					$forcage_prolongation=1;
				}
			}						
			//est-ce qu'on a le droit de prolonger
			if ($prolongation==TRUE) {
				$query = "update pret set cpt_prolongation='".$cpt_prolongation."' where pret_idexpl=".$id_prolong." limit 1";
				pmb_mysql_query($query, $dbh);
				
				global $pmb_gestion_financiere, $pmb_gestion_amende;
				
				// TIPOS COCOF
				// prise en compte de l'amende en cours en cas de retard
				if (($pmb_gestion_financiere) && ($pmb_gestion_amende)) {
					$amende=new amende($id_empr);
					$amende_t=$amende->get_amende($id_prolong);
					//Si il y a une amende, je la d�bite
					if ($amende_t["valeur"]) {
						print pmb_bidi("<br /><div class='erreur'>".$msg["finance_retour_amende"]."&nbsp;: ".comptes::format($amende_t["valeur"]));
						$alert_sound_list[]="critique";
						$compte_id=comptes::get_compte_id_from_empr($id_empr,2);
						if ($compte_id) {
							$cpte=new comptes($compte_id);
							if ($cpte->id_compte) {
								$cpte->record_transaction("",$amende_t["valeur"],-1,sprintf("Amende prolongation exemplaire %s",$id_prolong),0);
								print " ". "Prolongation : " . $msg["finance_retour_amende_recorded"];
							}
						}
						print "</div>";
					}
				}
				
				// TIPOS COCOF
				// prise en compte d'un co�t de pr�t lors d'une prolongation
				
				global $pmb_allow_extend_fee;
				global $pmb_gestion_tarif_prets;
				global $include_path,$lang;
				
				if ( ($pmb_gestion_financiere)&&($pmb_gestion_tarif_prets) && ($pmb_allow_extend_fee) ) {
					$tarif_pret=0;
					switch ($pmb_gestion_tarif_prets) {
						case 1:
							//Gestion simple
							$query = "SELECT tarif_pret";
							$query.= " FROM exemplaires, docs_type";
							$query.= " WHERE expl_id='".$id_prolong;
							$query.= "' and idtyp_doc=expl_typdoc LIMIT 1";
				
							$result = @ pmb_mysql_query($query, $dbh) or die("can't SELECT exemplaires ".$query);
							$expl_tarif = pmb_mysql_fetch_object($result);
							$tarif_pret = $expl_tarif -> tarif_pret;
				
							break;
						case 2:
							//Gestion avanc�e
							//Initialisation Quotas
							global $_parsed_quotas_;
							$_parsed_quotas_=false;
							$qt_tarif=new quota("COST_LEND_QUOTA","$include_path/quotas/own/$lang/finances.xml");
							$struct["READER"]=$id_empr;
							$struct["EXPL"]=$id_prolong;
							$tarif_pret=$qt_tarif->get_quota_value($struct);
							break;
					}
					$tarif_pret=$tarif_pret*1;
					if ($tarif_pret) {
						$compte_id=comptes::get_compte_id_from_empr($id_empr,3);
						if ($compte_id) {
							$cpte=new comptes($compte_id);
							$explaire = new exemplaire('',$id_prolong);
				
							if($explaire->id_notice == 0 && $explaire->id_bulletin){
								//C'est un exemplaire de bulletin
								$bulletin = new bulletinage_display($explaire->id_bulletin);
								$titre = strip_tags($bulletin->display);
							} elseif($explaire->id_notice) {
								$notice = new mono_display($explaire->id_notice);
								$titre = strip_tags($notice->header);
							}
							$libelle_expl = (strlen($titre)>15)?$explaire->cb." ".$titre:$explaire->cb." ".$titre;
							$cpte->record_transaction("",abs($tarif_pret),-1,sprintf("Prolongation exemplaire %s",$libelle_expl),0);
						}
					}
				}
				// END TIPOS COCOF : prolongation payante
						
				// mettre ici la routine de prolongation
				$pretProlong = new pret ($id_empr, $id_prolong, $form_cb, "", "");
				$resultProlongation = $pretProlong->prolongation ($date_retour);
				$erreur_affichage="";
			} else {
				if ($retour->expl_notice!=0) {
					$q= new notice($retour->expl_notice);
					$nom=$q->tit1;
				} elseif ($retour->expl_bulletin!=0) {		
					$query = "select bulletin_notice, bulletin_numero,date_date from bulletins where bulletin_id =".$retour->expl_bulletin;
					$res = pmb_mysql_query($query, $dbh);	
					$bull = pmb_mysql_fetch_object($res);
					$q= new serial($bull->bulletin_notice);
					$nom=$q->tit1.". ".$bull->bulletin_numero." (".formatdate($bull->date_date).")";
				}
				if($has_resa) {
					if ($bloc_prolongation==0) {
						$erreur_affichage="<table border='0' cellpadding='1' height='40' border='1'><tr><td width='33'><span><img src='./images/quest.png' /></span></td>
								<td width='100%'>";
						$erreur_affichage.=$msg["document_prolong"]." '$nom' : <span class='erreur'>${msg[393]}</span>";
						$erreur_affichage.="<input type='button' class='bouton' value='${msg[76]}' onClick=\"document.location='./circ.php?categ=pret&form_cb=".rawurlencode($form_cb)."'\">";
						$erreur_affichage.="&nbsp;<input type='button' class='bouton' value='${msg[pret_plolongation_forcage]}' onClick=\"document.location='circ.php?categ=pret&sub=pret_prolongation&form_cb=".rawurlencode($form_cb)."&cb_doc=$cb_doc&id_doc=".$id_prolong."&date_retour=$date_retour&force_prolongation=$forcage_prolongation'\">";
						$erreur_affichage.="</td></tr></table>";
					} else {
						$erreur_affichage.=$msg["document_prolong"]." '$nom' : <span class='erreur'>${msg[393]}</span><br />";
					}
				} else {
					if ($bloc_prolongation==0) {
						$erreur_affichage = "<hr />
						<div class='row'>
							<div class='colonne10'><img src='./images/error.png' /></div>
							<div class='colonne-suite'>".$msg["document_prolong"]." '$nom' : <span class='erreur'>".$msg[pret_plolongation_refuse]."</span></div>";
						$alert_sound_list[]="critique";
						$erreur_affichage.= "<input type='button' class='bouton' value='${msg[76]}' onClick=\"document.location='./circ.php?categ=pret&id_empr=$id_empr'\" />";
						$erreur_affichage.= "&nbsp;<input type='button' class='bouton' value='${msg[pret_plolongation_forcage]}' onClick=\"document.location='./circ.php?categ=pret&sub=pret_prolongation&form_cb=".rawurlencode($form_cb)."&cb_doc=$cb_doc&id_doc=".$id_prolong."&date_retour=$date_retour&force_prolongation=$forcage_prolongation'\" />";	
						$erreur_affichage.= "</div><br />";
					} else {
						$erreur_affichage.=$msg["document_prolong"]." '$nom' : <span class='erreur'>".$msg[pret_plolongation_refuse]."</span><br />";	
					}
				}			
			}
		}
	return $erreur_affichage; 
}


