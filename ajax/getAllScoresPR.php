<?php

include("../config.php");

if(isset($_POST['action']) && $_POST['action'] == "get-all-scores-pr"){

	try{

		$roomId = $_POST['roomId'];
		$sessionKey = $_POST['sessionKey'];
		$betValue = $_POST['betValue'];
		$playerWon = $_POST['playerWon'];

		$totalChips = 0.00;
		


		$jsonArr = array();

		/* Update game end */
		$sqlUpdateLosers = mysql_query("UPDATE room_tables SET status = 'end' WHERE session_key = '".$sessionKey."' AND game_id = '".$roomId."' LIMIT 1");

		$sqlUpdateLosers = mysql_query("UPDATE players SET status = 'end' WHERE session_key = '".$sessionKey."' AND game_id = '".$roomId."' LIMIT 1");

	

		/* Get score */
		
		 $sqlGetLostScores = mysql_query("SELECT total_points, user_id, deal_me_out, drop_and_go FROM player_gamedata WHERE game_id = '".$roomId."' AND session_key = '".$sessionKey."' AND status = 'over'");

		 while($row = mysql_fetch_assoc($sqlGetLostScores)){

		 $singleChip = $row['total_points'];
		 $totalChips += $row['total_points']; 
		 $loserId = $row['user_id'];
		 $dealMeOut = $row['deal_me_out'];
		 $dropAndGo = $row['drop_and_go'];

		 /* Update Loser's chips */

		 $sqlGetOtherInfos = mysql_query("SELECT balance_chips, refer_amount, redeemable_amount FROM real_wallet WHERE user_id = '".$loserId."' ORDER BY id DESC LIMIT 1");

		 	$row1 = mysql_fetch_assoc($sqlGetOtherInfos);

		    $balance_chips = $row1['balance_chips'];
			$refer_amount = $row1['refer_amount'];
			$redeemable_amount = $row1['redeemable_amount'];

			if($refer_amount >= $singleChip){
				$refer_amount = $refer_amount - $singleChip;
			}else if($refer_amount < $singleChip && $refer_amount != 0.00){
				$extraDeduction = $singleChip - $refer_amount;
				$refer_amount = 0;

				if($balance_chips <= $redeemable_amount){

					$redeemable_amount = $redeemable_amount - $singleChip;

				}
			}


			$balance_chips = $balance_chips - $singleChip;



		 $sqlUpdateLoserChips = mysql_query("UPDATE real_wallet SET lost_chips = '".$singleChip."', balance_chips = '".$balance_chips."', refer_amount = '".$refer_amount."', redeemable_amount = '".$redeemable_amount."' WHERE user_id = '".$loserId."' AND session_key = '".$sessionKey."' AND game_id = '".$roomId."' ");


		} 


		/* UPDATE PLAYER WON */

		$sqlUpdateStatus = mysql_query("UPDATE game_running SET player_won = ".$playerWon." WHERE game_id = '".$roomId."' AND session_key = '".$sessionKey."' LIMIT 1 ");

		if($sqlUpdateStatus){

	
			/*  Calculation Part */

			/* insert losers */
			$sqlUpdateLosers = mysql_query("UPDATE real_wallet SET game_result = 'lost' WHERE user_id != '".$playerWon."' AND session_key = '".$sessionKey."' AND game_id = '".$roomId."' ");



			if($sqlUpdateLosers){

			/* Calculate Tax */

			// service charge 10% on winning amount

			 $serviceCharge = ((10/100) * $totalChips);
			 $serviceTax = ((15/100) * $serviceCharge);

			 	if($totalChips >= 10000){
					$tds = ((30/100) * $totalChips);

				}else{
					$tds = 0.00;
				}



			$winningAmount = floatval($totalChips - ($serviceCharge+$serviceTax+$tds));

			

			/* insert winner */

		  $sqlUpdateWinner = mysql_query("UPDATE real_wallet SET game_result = 'winner', total_amount = '".$totalChips."', get_chips = '".$winningAmount."', balance_chips = balance_chips + '".$winningAmount."', redeemable_amount = redeemable_amount + '".$winningAmount."' WHERE user_id = '".$playerWon."' AND session_key = '".$sessionKey."' AND game_id = '".$roomId."' LIMIT 1");

		  if($sqlUpdateWinner){
		  	//echo " updated game result =========================== |||  ", $winningAmount;


		  	/* Check for bonus amounts for all the losers */

		  	$checkTheLosers = mysql_query("SELECT user_id, lost_chips FROM real_wallet WHERE session_key = '".$sessionKey."' AND game_id = '".$roomId."' AND game_result = 'lost' ");

		  
		  	while($rowGet = mysql_fetch_assoc($checkTheLosers)){


		  		$loser = $rowGet['user_id'];
		  		$lostChips = $rowGet['lost_chips'];

		  		if($lostChips > 100){  /* Lost Chip limit set to 100 */


		  		/* Check if the user has any bonus */

		  		$checkIfBonus = mysql_query("SELECT * FROM bonus_details WHERE user_id = '".$loser."' AND status = 1 AND bonus_amount > 0");

		  		$bonusCount = mysql_num_rows($checkIfBonus);

		  		if($bonusCount == 1){

		  			/* get bonus details */
		  			$rowBonus = mysql_fetch_assoc($checkIfBonus);

		  			$bonus_amount = $rowBonus['bonus_amount'];
		  			$bonus_datetime = $rowBonus['date'];
		  			$bonus_time = $rowBonus['time'];
		  			$bonus_timeDuration = $rowBonus['time_duration'];


		  			/* Check if the bonus timing expired or not */

		  			$bonus_datetime = new DateTime($bonus_datetime);

		  			 
					 $dateNow = new DateTime(date('Y-m-d h:i:s a', time())); 

					 $diff = $dateNow->diff($bonus_datetime); 
					 $hours = $diff->h; 
					 $hours = $hours + ($diff->days*24); 
					 // echo $hours;

		  

					if($hours > $bonus_timeDuration){
						/* bonus expired */


						$updateBonus = mysql_query("UPDATE bonus_details SET status = 0 WHERE user_id = '".$loser."' ");
					
					}else if($hours <= $bonus_timeDuration){

						/* deduct from bonus */

						/* Bonus calculation == 10% of lost chips */
			  			$bonusDeduct = ((10/100) * $lostChips);
			  			
			  			if($bonus_amount <= $bonusDeduct){
			  				$bonus_amount = 0;
			  			}else{
			  				$bonus_amount = floatval($bonus_amount - $bonusDeduct);
			  			}

			  			/* update bonus */

			  			$updateBonus = mysql_query("UPDATE bonus_details SET bonus_amount = '".$bonus_amount."', deduction_amount = deduction_amount + '".$bonusDeduct."' WHERE user_id = '".$loser."' LIMIT 1");

			  			/*get balance chips*/

			  			$get_balance_chip = mysql_query("SELECT balance_chips, redeemable_amount FROM real_wallet WHERE user_id = '".$loser."' AND session_key = '".$sessionKey."' AND game_id = '".$roomId."' ORDER BY id DESC LIMIT 1");
			  			$rowBalance = mysql_fetch_assoc($get_balance_chip);
			  			
			  			$balance_chip_get = $rowBalance['balance_chips'];
			  			$balance_chip_add = floatval($balance_chip_get+$bonusDeduct);

			  			$redeemable_amount_get = $rowBalance['redeemable_amount'];

			  			/* insert real chips for bonus */

			  			$sqlInsert = mysql_query("INSERT INTO real_wallet (description, get_chips, bonus_chips, balance_chips, payment_getaway, payment_status, `date`, user_id, redeemable_amount) VALUES ('promo', '".$bonusDeduct."', '".$bonusDeduct."', '".$balance_chip_add."', 'promo', 1, '".$date."', '".$loser."', '".$redeemable_amount_get."')");

			  			// $sqlRemove1 = mysql_query("DELETE FROM game_running WHERE game_id = '".$roomId."' AND session_key = '".$sessionKey."' ");

		  			 //    $sqlRemove2 = mysql_query("DELETE FROM player_gamedata WHERE game_id = '".$roomId."' AND session_key = '".$sessionKey."' ");


					}

				}else if($bonusCount > 1){

					$dataArray = array();

					
					while($rowBonus = mysql_fetch_assoc($checkIfBonus)){

			  			$bonus_amount = $rowBonus['bonus_amount'];
			  			$bonus_datetime = $rowBonus['date'];
			  			$bonus_time = $rowBonus['time'];
			  			$bonus_timeDuration = $rowBonus['time_duration'];
			  			$bonus_id = $rowBonus['id'];


			  			/* Check if the bonus timing expired or not */

			  			$bonus_datetime = new DateTime($bonus_datetime);

			  			 
						 $dateNow = new DateTime(date('Y-m-d h:i:s a', time())); 

						 $diff = $dateNow->diff($bonus_datetime); 
						 $hours = $diff->h; 
						 $hours = $hours + ($diff->days*24); 
						 
						
						 

						 if($hours <= $bonus_timeDuration){
						 	$dataArray[$bonus_id] = $hours;

						 }else{
						 	/* bonus expired */
						 	$updateBonus = mysql_query("UPDATE bonus_details SET status = 0 WHERE id = '".$bonus_id."' AND user_id = '".$loser."' LIMIT 1");

						 }



					}

					/* Search for min value */

					$key = array_search(max($dataArray), $dataArray); 

					/* Key is the bonus id */
					$getBonus = mysql_query("SELECT * FROM bonus_details WHERE id = '".$key."' AND user_id = '".$loser."' LIMIT 1");
					$rowBonus1 = mysql_fetch_assoc($getBonus);

					$bonus_amount = $rowBonus1['bonus_amount'];
		  			$bonus_datetime = $rowBonus1['date'];
		  			$bonus_time = $rowBonus1['time'];
		  			$bonus_timeDuration = $rowBonus1['time_duration'];
		  			$bonus_id = $rowBonus1['id'];


					/* Bonus calculation == 10% of lost chips */
		  			$bonusDeduct = ((10/100) * $lostChips);
		  			
		  			if($bonus_amount <= $bonusDeduct){
		  				$bonus_amount = 0;
		  			}else{
		  				$bonus_amount = floatval($bonus_amount - $bonusDeduct);
		  			}

		  			/* update bonus */

		  			$updateBonus = mysql_query("UPDATE bonus_details SET bonus_amount = '".$bonus_amount."', deduction_amount = deduction_amount + '".$deduction_amount."' WHERE user_id = '".$loser."' AND  id = '".$key."' LIMIT 1");

		  			/*get balance chips*/

		  			$get_balance_chip = mysql_query("SELECT balance_chips, redeemable_amount FROM real_wallet WHERE user_id = '".$loser."' AND session_key = '".$sessionKey."' AND game_id = '".$roomId."' ORDER BY id DESC LIMIT 1");
		  			$rowBalance = mysql_fetch_assoc($get_balance_chip);
		  			
		  			$balance_chip_get = $rowBalance['balance_chips'];
		  			$balance_chip_add = floatval($balance_chip_get+$bonusDeduct);

		  			$redeemable_amount_get = $rowBalance['redeemable_amount'];

		  			/* insert real chips for bonus */

		  			$sqlInsert = mysql_query("INSERT INTO real_wallet (description, get_chips, bonus_chips, balance_chips, payment_getaway, payment_status, `date`, user_id, redeemable_amount) VALUES ('promo', '".$bonusDeduct."', '".$bonusDeduct."', '".$balance_chip_add."', 'promo', 1, '".$date."', '".$loser."', '".$redeemable_amount_get."')");

		  			// $sqlRemove1 = mysql_query("DELETE FROM game_running WHERE game_id = '".$roomId."' AND session_key = '".$sessionKey."' ");

		  			// $sqlRemove2 = mysql_query("DELETE FROM player_gamedata WHERE game_id = '".$roomId."' AND session_key = '".$sessionKey."' ");


		  			if($sqlInsert){
		  				echo "bonus deduct done.... ============================ 3";
		  			}


				} /* bonus exist */


		  	  }



		  	}




		  }





			$jsonArr['totalChipsLost'] = $totalChips;
			$jsonArr['winningAmount'] = $winningAmount;
			$jsonArr['playerWon'] = $playerWon;
			$jsonArr['dealMeOut'] = $dealMeOut;
			$jsonArr['dropAndGo'] = $dropAndGo;


		    echo json_encode($jsonArr);



		}



	}

	


		


	}catch(Exception $e){
		echo $e->getMessage();
	}

}

?>
