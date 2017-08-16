<?php
// +-------------------------------------------------+
// � 2002-2004 PMB Services / www.sigb.net pmb@sigb.net et contributeurs (voir www.sigb.net)
// +-------------------------------------------------+
// $Id: update_notice.inc.php,v 1.29 2015-04-03 11:16:25 jpermanne Exp $

if (stristr($_SERVER['REQUEST_URI'], ".inc.php")) die("no access");

//Pour les champs personnalises
require_once($class_path."/parametres_perso.class.php");
require_once($class_path."/notice.class.php");
require_once($include_path."/isbn.inc.php");
require_once($class_path."/author.class.php");

//droits d'acces
if ($gestion_acces_active==1) {
	require_once("$class_path/acces.class.php");
	$ac= new acces();
	$dom_1= $ac->setDomain(1);
}

//verification des droits de modification notice
$acces_m=1;
if ($id!=0 && $gestion_acces_active==1 && $gestion_acces_user_notice==1) {
	$acces_m = $dom_1->getRights($PMBuserid,$id,8);
}

if ($acces_m==0) {

	error_message('', htmlentities($dom_1->getComment('mod_noti_error'), ENT_QUOTES, $charset), 1, '');

} else {
	
	//Pour les champs personnalises
	require_once($class_path."/parametres_perso.class.php");
	require_once($class_path."/notice.class.php");
	require_once($class_path."/notice_doublon.class.php");
	
	//Pour la synchro rdf
	require_once($class_path."/synchro_rdf.class.php");
	if($pmb_synchro_rdf){
		$synchro_rdf = new synchro_rdf();
	}
	
	// Pour l'indexation des concepts
	require_once($class_path."/index_concept.class.php");
	
	$sign = new notice_doublon();
	$signature = $sign->gen_signature();
	if ($forcage == 1) {
		$tab= unserialize(stripslashes($ret_url));
		foreach($tab->GET as $key => $val){
			if (get_magic_quotes_gpc())
				$GLOBALS[$key] = $val;
			else {
				add_sl($val);
				$GLOBALS[$key] = $val;
			}
		}	
		foreach($tab->POST as $key => $val){
			if (get_magic_quotes_gpc())
				$GLOBALS[$key] = $val;
			else {
				add_sl($val);
				$GLOBALS[$key] = $val;
			}
		}
		
		$signature = $sign->gen_signature();
	} else if( $pmb_notice_controle_doublons != 0 ) {	
		//Si controle de dedoublonnage active	
		
		$signature = $sign->gen_signature();
		// En modification de notice, on ne dedoublonne pas 
		if(!$id) {
			$requete="select signature, niveau_biblio ,notice_id from notices where signature='$signature' and niveau_biblio='$b_level' ";
			if($id)	$requete.= " and notice_id != '$id' ";

			$result=pmb_mysql_query($requete, $dbh);	
			if ($dbls=pmb_mysql_num_rows($result)) {
				//affichage de l'erreur, en passant tous les param postes (serialise) pour l'eventuel forcage 	
				$tab=new stdClass();
				$tab->POST = $_POST;
				$tab->GET = $_GET;
				$ret_url= htmlentities(serialize($tab), ENT_QUOTES,$charset);
				require_once("$class_path/mono_display.class.php");
				
				print "
					<br /><div class='erreur'>$msg[540]</div>
					<script type='text/javascript' src='./javascript/tablist.js'></script>
					<div class='row'>
						<div class='colonne10'>
							<img src='./images/error.gif' align='left'>
						</div>
						<div class='colonne80'>
							<strong>".$msg["gen_signature_erreur_similaire"]."</strong>
						</div>
					</div>
					<div class='row'>
						<form class='form-$current_module' name='dummy'  method='post' action='./catalog.php?categ=update&id=$id'>
							<input type='hidden' name='forcage' value='1'>
							<input type='hidden' name='signature' value='$signature'>
							<input type='hidden' name='ret_url' value='$ret_url'>
							<input type='button' name='ok' class='bouton' value=' $msg[76] ' onClick='history.go(-1);'>
							<input type='submit' class='bouton' name='bt_forcage' value=' ".htmlentities($msg["gen_signature_forcage"], ENT_QUOTES,$charset)." '>
						</form>
						
					</div>
					";
				if($dbls<$nb_per_page_search){
					$maxAffiche=$dbls;
					echo "<div class='row'><strong>".sprintf($msg["gen_signature_erreur_similaire_nb"],$dbls,$dbls)."</strong></div>";
				}else{
					$maxAffiche=$nb_per_page_search;
					echo "<div class='row'><strong>".sprintf($msg["gen_signature_erreur_similaire_nb"],$maxAffiche,$dbls)."</strong></div>";
				}
				$enCours=1;
				while($enCours<=$maxAffiche){
					$r=pmb_mysql_fetch_object($result);
					$nt = new mono_display($r->notice_id);
					
					echo "
						<div class='row'>
						$nt->result
				 	    </div>
						<script>document.getElementById('el1Child').setAttribute('startOpen','Yes');</script>
						<script type='text/javascript'>document.forms['dummy'].elements['ok'].focus();</script>";
					$enCours++;
				}
				exit();
			}
		}
	} //fin du controle de dedoublonage
	
	//synchro_rdf
	if($pmb_synchro_rdf && $id){
		$synchro_rdf->delRdf($id,0);
	}
	
	// definition de quelques variables
	$requete = "INSERT INTO notices SET create_date=sysdate(), update_date=sysdate(), ";
	$notitle_message = $msg[279];
	
	if($id) {
		$requete = "UPDATE notices SET update_date=sysdate(), ";
		$postrequete = " WHERE notice_id=$id ";
		$notitle_message = $msg[280];
	}
	
	$p_perso=new parametres_perso("notices");
	$nberrors=$p_perso->check_submited_fields();
	
	if(($f_tit1)&&trim($f_tit1)&&(!$nberrors)) {
	
		// nettoyage des champs du form
		$f_tit1		=	clean_string($f_tit1);	
		$f_tit2		=	clean_string($f_tit2);
		$f_tit3		=	clean_string($f_tit3);
		$f_tit4		=	clean_string($f_tit4);
		$f_tparent	=	clean_string($f_tparent);
		$f_tnvol	=	clean_string($f_tnvol);
		$f_ed1		=	clean_string($f_ed1);
		$f_ed2		=	clean_string($f_ed2);
		$f_coll		=	clean_string($f_coll);
		$f_subcoll	=	clean_string($f_subcoll);
		$f_year		=	clean_string($f_year);
		$f_nocoll	=	clean_string($f_nocoll);
		$f_mention_edition	=	clean_string($f_mention_edition);
		$f_cb		=	clean_string($f_cb);
		$f_npages	=	clean_string($f_npages);
		$f_ill		=	clean_string($f_ill);
		$f_size		=	clean_string($f_size);
		$f_prix		=	clean_string($f_prix);
		$f_accomp	=	clean_string($f_accomp);
		$f_lien		=	clean_string($f_lien);
		$f_eformat	=	clean_string($f_eformat);
		
		// le contenu des champs du form est mis dans un tableau
		$t_notice['typdoc'] = $typdoc;
		$t_notice['statut'] = $form_notice_statut;
		$t_notice['commentaire_gestion'] = $f_commentaire_gestion;
		$t_notice['thumbnail_url'] = $f_thumbnail_url;
		
		$t_notice['tit1'] = $f_tit1;
		$t_notice['tit2'] = $f_tit2;
		$t_notice['tit3'] = $f_tit3;
		$t_notice['tit4'] = $f_tit4;
		if ($f_tparent && $f_tparent_id) $t_notice['tparent_id'] = $f_tparent_id ;
		else $t_notice['tparent_id'] = 0;
		$t_notice['tnvol'] = $f_tnvol;

		// Titres uniformes
		global $pmb_use_uniform_title;
		if ($pmb_use_uniform_title) {
			for ($i=0; $i<$max_titre_uniforme ; $i++) {
				$var_tu_id = "f_titre_uniforme_code$i" ;
				$var_ntu_titre = "ntu_titre$i" ;
				$var_ntu_date = "ntu_date$i" ;
				$var_ntu_sous_vedette = "ntu_sous_vedette$i" ;
				$var_ntu_langue = "ntu_langue$i" ;
				$var_ntu_version = "ntu_version$i" ;
				$var_ntu_mention = "ntu_mention$i" ;
			
				$titres_uniformes[] = array (
					'num_tu' => $$var_tu_id,
					'ntu_titre' => $$var_ntu_titre,
					'ntu_date' => $$var_ntu_date,
					'ntu_sous_vedette' => $$var_ntu_sous_vedette,
					'ntu_langue' => $$var_ntu_langue,
					'ntu_version' => $$var_ntu_version,
					'ntu_mention' => $$var_ntu_mention )
				;
			}
		}
	
		// auteur principal
		$f_aut[] = array (
				'id' => $f_aut0_id,
				'fonction' => $f_f0_code,
				'type' => '0',
				'ordre' => 0 );
		
		// autres auteurs
		for ($i=0; $i<$max_aut1; $i++) {
			$var_autid = "f_aut1_id$i" ;
			$var_autfonc = "f_f1_code$i" ;
			$f_aut[] = array (
					'id' => $$var_autid,
					'fonction' => $$var_autfonc,
					'type' => '1',
					'ordre' => $i );
		}
		
		// auteurs secondaires
		for ($i=0; $i<$max_aut2 ; $i++) {
		
			$var_autid = "f_aut2_id$i" ;
			$var_autfonc = "f_f2_code$i" ;
			$f_aut[] = array (
					'id' => $$var_autid,
					'fonction' => $$var_autfonc,
					'type' => '2',
					'ordre' => $i );
		}
	
		$f_ed1 ? $t_notice['ed1_id'] = $f_ed1_id : $t_notice['ed1_id'] = 0;
		$f_ed2 ? $t_notice['ed2_id'] = $f_ed2_id : $t_notice['ed2_id'] = 0;
		$f_coll && $t_notice['ed1_id'] ? $t_notice['coll_id'] = $f_coll_id : $t_notice['coll_id'] = 0;
		$f_subcoll && $t_notice['coll_id'] ? $t_notice['subcoll_id'] = $f_subcoll_id : $t_notice['subcoll_id'] = 0;
		$t_notice['year'] = trim($f_year) ;
		$f_nocoll && $t_notice['coll_id'] ? $t_notice['nocoll'] = trim($f_nocoll) : $t_notice['nocoll'] = '';
		$t_notice['mention_edition'] = trim($f_mention_edition) ; 
	
		if ($f_cb) {
			// ce controle redondant est la pour le cas ou l'utilisateur aurait change le code
			if(isEAN($f_cb)) {
				// la saisie est un EAN -> on tente de le formater en ISBN
				$code = EANtoISBN($f_cb);
				// si echec, on prend l'EAN comme il vient
				if(!$code) $code = $f_cb;
			} else {
				if(isISBN($f_cb)) {
					// si la saisie est un ISBN
					$code = formatISBN($f_cb,13);
					// si echec, ISBN errone on le prend sous cette forme
					if(!$code) $code = $f_cb;
				} else {
					// ce n'est rien de tout ca, on prend la saisie telle quelle
					$code = $f_cb;
				}
			}
			$t_notice['code'] = $code;
		}
	
		$t_notice['npages'] = $f_npages;
		$t_notice['ill'] = $f_ill;
		$t_notice['size'] = $f_size;
		$t_notice['prix'] = $f_prix;
		$t_notice['accomp'] = $f_accomp;
	
		$t_notice['n_gen'] = $f_n_gen;
		$t_notice['n_contenu'] = $f_n_contenu;
		$t_notice['n_resume'] = $f_n_resume;
	
		// categories		
		if($tab_categ_order){
			$categ_order=explode(",",$tab_categ_order);
			$order=0;
			foreach($categ_order as $old_order){
				$var_categid = "f_categ_id$old_order" ;
				if($var_categid){
					$f_categ[] = array (
							'id' => $$var_categid,
							'ordre' => $order );
					$order++;
				}	
			}
		}else{
			for ($i=0; $i< $max_categ ; $i++) {
				$var_categid = "f_categ_id$i" ;
				$f_categ[] = array (
						'id' => $$var_categid,
						'ordre' => $i );
			}
		}	
		$f_indexint ? $t_notice['indexint'] = $f_indexint_id : $t_notice['indexint']= 0;
	
		$f_lien ? $t_notice['lien'] = $f_lien : $t_notice['lien'] = '';
		$t_notice['lien'] && $f_eformat ? $t_notice['eformat'] = $f_eformat : $t_notice['eformat'] = '';
	
		$b_level ? $t_notice['b_level'] = $b_level : $t_notice['b_level'] = 'm';
		$h_level ? $t_notice['h_level'] = $h_level : $t_notice['h_level'] = '0';
	
		$date_parution_notice = notice::get_date_parution($t_notice['year']);
		
		//Champ signature
		$t_notice['signature'] = $signature;
		$t_notice['indexation_lang'] = $indexation_lang;
	
		$requete .= " typdoc='${t_notice['typdoc']}'";
		$requete .= ", tit1='${t_notice['tit1']}'";
		$requete .= ", tit2='${t_notice['tit2']}'";
		$requete .= ", tit3='${t_notice['tit3']}'";
		$requete .= ", tit4='${t_notice['tit4']}'";
		$requete .= ", tparent_id=${t_notice['tparent_id']}";
		$requete .= ", tnvol='${t_notice['tnvol']}'";
		$requete .= ", ed1_id='${t_notice['ed1_id']}'";
		$requete .= ", ed2_id='${t_notice['ed2_id']}'";
		$requete .= ", coll_id='${t_notice['coll_id']}'";
		$requete .= ", subcoll_id='${t_notice['subcoll_id']}'";
		$requete .= ", year='${t_notice['year']}'";
		$requete .= ", nocoll='${t_notice['nocoll']}'";
		$requete .= ", mention_edition='${t_notice['mention_edition']}'";
		$requete .= ", code='${t_notice['code']}'";
		$requete .= ", npages='${t_notice['npages']}'";
		$requete .= ", ill='${t_notice['ill']}'";
		$requete .= ", size='${t_notice['size']}'";
		$requete .= ", prix='${t_notice['prix']}'";
		$requete .= ", accomp='${t_notice['accomp']}'";
		$requete .= ", n_gen='${t_notice['n_gen']}'";
		$requete .= ", n_contenu='${t_notice['n_contenu']}'";
		$requete .= ", n_resume='$t_notice[n_resume]'";
		$requete .= ", indexint='${t_notice['indexint']}'";
		$requete .= ", index_l='".clean_tags($f_indexation)."'";
		$requete .= ", lien='${t_notice['lien']}'";
		$requete .= ", eformat='${t_notice['eformat']}'";
		$requete .= ", niveau_biblio='${t_notice['b_level']}'";
		$requete .= ", niveau_hierar='${t_notice['h_level']}'";
		$requete .= ", statut='${t_notice['statut']}'";
		$requete .= ", commentaire_gestion='${t_notice['commentaire_gestion']}'";
		$requete .= ", thumbnail_url='${t_notice['thumbnail_url']}'";
		$requete .= ", signature='${t_notice['signature']}'";
		$requete .= ", date_parution='$date_parution_notice'";
		$requete .= ", indexation_lang='${t_notice['indexation_lang']}'";
		$requete .= $postrequete;
	
		$result = pmb_mysql_query($requete, $dbh);
		
		//traitement audit
		if (!$id) {
			$sav_id=0;
			$id=pmb_mysql_insert_id($dbh);
			audit::insert_creation (AUDIT_NOTICE, $id) ;
		} else {
			$sav_id=$id;
			audit::insert_modif (AUDIT_NOTICE, $id) ;
		}
		
		
		// Traitement des titres uniformes
		if ($pmb_use_uniform_title) {
			$ntu=new tu_notice($id);
			$ntu->update($titres_uniformes);
		}
		
		//traitement des droits acces user_notice
		if ($gestion_acces_active==1 && $gestion_acces_user_notice==1) {			
			$dom_1->storeUserRights($sav_id, $id, $res_prf, $chk_rights, $prf_rad, $r_rad);
		}
			
		//traitement des droits acces empr_notice
		if ($gestion_acces_active==1 && $gestion_acces_empr_notice==1) {
			$dom_2= $ac->setDomain(2);
			$dom_2->storeUserRights($sav_id, $id, $res_prf, $chk_rights, $prf_rad, $r_rad);	
		}
			
		//Traitement des liens
		$requete="delete from notices_relations where num_notice=".$id;
		pmb_mysql_query($requete);
		for ($i=0; $i<$max_rel; $i++) {
			$f_rel_id="f_rel_id_".$i;
			$f_rel_type="f_rel_type_".$i;
			$f_rel_rank="f_rel_rank_".$i;
			if ($$f_rel_id) {
				if(!$sav_id){
					$requete_rank = "select count(rank) as rank_max from notices_relations where linked_notice='".$$f_rel_id."' and relation_type='".$$f_rel_type."'";
					$res = pmb_mysql_query($requete_rank);
					if(pmb_mysql_num_rows($res))
						$rang_max = pmb_mysql_result($res,0,0);
					else $rang_max = 0;	
					$requete="insert into notices_relations values($id,".$$f_rel_id.",'".$$f_rel_type."',".($rang_max ? $rang_max :$i).")";
					@pmb_mysql_query($requete);
				} else {
					$req_exist = "select 1 from notices_relations where linked_notice='".$$f_rel_id."' and relation_type='".$$f_rel_type."' and rank='".$$f_rel_rank."'";
					$res_exist = pmb_mysql_query($req_exist);
					if(pmb_mysql_num_rows($res_exist)){
						$requete_rank = "select count(rank) as rank_max from notices_relations where linked_notice='".$$f_rel_id."' and relation_type='".$$f_rel_type."'";
						$res = pmb_mysql_query($requete_rank);
						if(pmb_mysql_num_rows($res))
							$rang_max = pmb_mysql_result($res,0,0);
						else $rang_max = $$f_rel_rank;	
					} else $rang_max = $$f_rel_rank;				
					$requete="insert into notices_relations values($id,".$$f_rel_id.",'".$$f_rel_type."',".$rang_max.")";
					@pmb_mysql_query($requete);
				}
			}
		}
		
		// traitement des auteurs
		$rqt_del = "delete from responsability where responsability_notice='$id' ";
		$res_del = pmb_mysql_query($rqt_del);
		$rqt_ins = "insert into responsability (responsability_author, responsability_notice, responsability_fonction, responsability_type, responsability_ordre) VALUES ";
		$i=0;
		while ($i<=count ($f_aut)-1) {
			$id_aut=$f_aut[$i]['id'];
			if ($id_aut) {
				$fonc_aut = $f_aut[$i]['fonction'];
				$type_aut = $f_aut[$i]['type'];
				$ordre_aut = $f_aut[$i]['ordre'];
				$rqt = $rqt_ins . " ('$id_aut','$id','$fonc_aut','$type_aut', $ordre_aut) " ; 
				$res_ins = @pmb_mysql_query($rqt);
			}
			$i++;
		}
			
		// traitement des categories
		$rqt_del = "DELETE FROM notices_categories WHERE notcateg_notice='$id' ";
		$res_del = pmb_mysql_query($rqt_del, $dbh);
		$rqt_ins = "INSERT INTO notices_categories (notcateg_notice, num_noeud, ordre_categorie) VALUES ";
		if(!isset($f_categ)) $f_categ = array();
		while (list ($key, $val) = each ($f_categ)) {
			$id_categ=$val['id'];
			if ($id_categ) {
				$ordre_categ = $val['ordre'];
				$rqt = $rqt_ins . " ('$id','$id_categ',$ordre_categ) " ; 
				$res_ins = @pmb_mysql_query($rqt, $dbh);
			}
		}
		
		// traitement des concepts
		if($thesaurus_concepts_active == 1){
			$index_concept = new index_concept($id, TYPE_NOTICE);
			$index_concept->save();
		}
		// traitement des langues
		// langues
		$f_lang_form = array();
		$f_langorg_form = array() ;
		for ($i=0; $i< $max_lang ; $i++) {
			$var_langcode = "f_lang_code$i" ;
			if ($$var_langcode) $f_lang_form[] =  array ('code' => $$var_langcode,'ordre' => $i);
		}
	
		// langues originales
		for ($i=0; $i< $max_langorg ; $i++) {
			$var_langorgcode = "f_langorg_code$i" ;
			if ($$var_langorgcode) $f_langorg_form[] =  array ('code' => $$var_langorgcode,'ordre' => $i);
		}
	
		$rqt_del = "delete from notices_langues where num_notice='$id' ";
		$res_del = pmb_mysql_query($rqt_del, $dbh);
		$rqt_ins = "insert into notices_langues (num_notice, type_langue, code_langue, ordre_langue) VALUES ";
		while (list ($key, $val) = each ($f_lang_form)) {
			$tmpcode_langue=$val['code'];
			if ($tmpcode_langue) {
				$tmpordre_langue = $val['ordre'];
				$rqt = $rqt_ins . " ('$id',0, '$tmpcode_langue', $tmpordre_langue) " ; 
				$res_ins = pmb_mysql_query($rqt, $dbh);
			}
		}
		
		// traitement des langues originales
		$rqt_ins = "insert into notices_langues (num_notice, type_langue, code_langue, ordre_langue) VALUES ";
		while (list ($key, $val) = each ($f_langorg_form)) {
			$tmpcode_langue=$val['code'];
			if ($tmpcode_langue) {
				$tmpordre_langue = $val['ordre'];
				$rqt = $rqt_ins . " ('$id',1, '$tmpcode_langue', $tmpordre_langue) " ; 
				$res_ins = @pmb_mysql_query($rqt, $dbh);
			}
		}
		
		//Traitement des champs personnalises
		$p_perso->rec_fields_perso($id);
		
		if($result) {
			
		} else {
			// echec de la requete
			error_message($libelle, $msg[281], 1, "./acquisition.php?categ=sug&action=modif&id_bibli=0&id_sug=".$id_sug);
		}

		// permet de charger la bonne langue, mot vide...
		$info=notice::indexation_prepare($id);
		// Mise a jour des index de la notice
		notice::majNotices($id);
		// Mise a jour de la table notices_global_index
		notice::majNoticesGlobalIndex($id);
		// Mise a jour de la table notices_mots_global_index
		notice::majNoticesMotsGlobalIndex($id);
		// restaure l'environnement de langue
		notice::indexation_restaure($info);
		//synchro_rdf
		if($pmb_synchro_rdf){
			$synchro_rdf->addRdf($id,0);
		}
		
		//Mise a jour de la suggestion
		$sug = new suggestions($id_sug);
		$sug->titre = stripslashes($t_notice['tit1']);
		$f_aut[] = array (
				'id' => $f_aut0_id,
				'fonction' => $f_f0_code,
				'type' => '0' );
		if ($f_aut0_id) {
			$auteur = new auteur($f_aut0_id);
			$sug->auteur = $auteur->display;		
		}
		if ($f_ed1_id)	$sug->editeur = stripslashes($f_ed1); 
		$sug->code = $t_notice['code'];
		$t_notice['prix'] = str_replace(',','.',$t_notice['prix']); //float(8,2)
		$sug->prix = $t_notice['prix'];
		$sug->num_notice = $id;
		$sug->save(); 
		
		
	} else {
		if ($f_tit1=="") {
			// erreur : le champ tit1 est vide
			error_message($libelle, $notitle_message, 1, "./acquisition.php?categ=sug&action=modif&id_bibli=0&id_sug=".$id_sug);
		} else {
			error_message_history($msg["notice_champs_perso"],$p_perso->error_message,1);
		}
	}
}
