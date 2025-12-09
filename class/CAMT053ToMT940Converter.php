<?php

class CAMT053ToMT940Converter
{
	private $xml;
	private $namespace;

	public function __construct()
	{
		$this->namespace = [
			'camt' => 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.08'
		];
	}

	public function convertFile($camtFile, $mt940File)
	{
		$xmlContent = file_get_contents($camtFile);
		$mt940Content = $this->convert($xmlContent);
		
		// Nach ISO-8859-1 konvertieren für MT940
		$mt940ContentIso = mb_convert_encoding($mt940Content, 'ISO-8859-1', 'UTF-8');
		
		file_put_contents($mt940File, $mt940ContentIso);
		return $mt940Content;
	}

	public function convert($xmlContent)
	{
		$this->xml = simplexml_load_string($xmlContent);
		$this->xml->registerXPathNamespace('camt', $this->namespace['camt']);

		$mt940 = [];

		// Statements durchlaufen
		$statements = $this->xml->xpath('//camt:Stmt');

		foreach ($statements as $stmt) {
			$mt940[] = $this->convertStatement($stmt);
		}

		return implode("\r\n-\r\n", $mt940) . "\r\n-";
	}

	private function convertStatement($stmt)
	{
		$lines = [];

		// :20: Transaktionsreferenz
		$stmtId = (string)$stmt->Id;
		$lines[] = ":20:{$stmtId}";

		// :25: Kontonummer (BLZ/Kontonummer aus IBAN)
		$iban = (string)$stmt->Acct->Id->IBAN;
		$blzKonto = $this->extractBLZKontoFromIBAN($iban);
		$lines[] = ":25:{$blzKonto}";

		// :28C: Statement Nummer
		$stmtNr = (string)$stmt->ElctrncSeqNb ?: '1';
		$lines[] = ":28C:{$stmtNr}";

		// :60F: Opening Balance
		$openingBalance = $stmt->Bal[0];
		$lines[] = $this->formatBalance(':60F:', $openingBalance);

		// :61: Transaktionen
		foreach ($stmt->Ntry as $entry) {
			$lines[] = $this->formatTransaction($entry);

			// :86: Verwendungszweck
			$lines[] = $this->formatPurpose($entry);
		}

		// :62F: Closing Balance
		$closingBalance = $stmt->Bal[1];
		$lines[] = $this->formatBalance(':62F:', $closingBalance);

		return implode("\r\n", $lines);
	}

	private function formatBalance($tag, $balance)
	{
		$cdtDbt = ((string)$balance->CdtDbtInd === 'CRDT') ? 'C' : 'D';
		$date = $this->formatDate((string)$balance->Dt->Dt);
		$currency = (string)$balance->Amt['Ccy'];
		$amount = $this->formatAmount((string)$balance->Amt);

		return "{$tag}{$cdtDbt}{$date}{$currency}{$amount}";
	}

	private function formatTransaction($entry)
	{
		$valueDate = $this->formatDate((string)$entry->ValDt->Dt);
		$bookingDate = substr($this->formatDate((string)$entry->BookgDt->Dt), 2, 4); // Nur MMDD

		$cdtDbt = ((string)$entry->CdtDbtInd === 'CRDT') ? 'C' : 'D';

		// Storno-Kennung
		$reversed = isset($entry->RvslInd) && (string)$entry->RvslInd === 'true';
		if ($reversed) {
			$cdtDbt = ($cdtDbt === 'C') ? 'RC' : 'RD';
		}

		// Betrag mit Komma für MT940
		$amount = $this->formatAmount((string)$entry->Amt);

		// Buchungsschlüssel (4 Zeichen)
		$bookingCode = 'NMSC';
		if (isset($entry->BkTxCd->Prtry->Cd)) {
			$bookingCode = substr((string)$entry->BkTxCd->Prtry->Cd, 0, 4);
		}

		// Referenz (max 16 Zeichen)
		$reference = substr((string)$entry->AcctSvcrRef, 0, 16);

		// Format: :61:YYMMDDMMDD[C/D/RC/RD][Betrag]N[Code][Referenz]
		return ":61:{$valueDate}{$bookingDate}{$cdtDbt}{$amount}{$bookingCode}{$reference}";
	}

	private function formatPurpose($entry)
	{
		// Strukturiertes Format für :86: verwenden
		$fields = [];

		// Geschäftsvorfallcode (3-stellig) extrahieren
		$gvc = $this->extractGVC($entry);

		// ?00 Buchungstext mit GVC
		$bookingText = 'SEPA-Überweisung';
		if (isset($entry->AddtlNtryInf)) {
			$bookingText = (string)$entry->AddtlNtryInf;
		}
		$fields['00'] = $this->truncate($bookingText, 24);

		// ?10 Primanoten-Nummer (optional)
		if (isset($entry->NtryDtls->TxDtls->Refs->PmtInfId)) {
			$fields['10'] = $this->truncate((string)$entry->NtryDtls->TxDtls->Refs->PmtInfId, 27);
		}

		// ?20-?29 Verwendungszweck (bis zu 10x27 Zeichen)
		$verwendungszweck = '';
		if (isset($entry->NtryDtls->TxDtls->RmtInf->Ustrd)) {
			$verwendungszweck = (string)$entry->NtryDtls->TxDtls->RmtInf->Ustrd;
		}

		if ($verwendungszweck !== '') {
			$vzLines = str_split($verwendungszweck, 27);
			for ($i = 0; $i < min(count($vzLines), 10); $i++) {
				$fields[20 + $i] = $vzLines[$i];
			}
		}

		// ?30 BLZ Gegenseite
		$gegenBLZ = '';
		if (isset($entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->IBAN)) {
			$iban = (string)$entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->IBAN;
			$gegenBLZ = $this->extractBLZFromIBAN($iban);
		} elseif (isset($entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->IBAN)) {
			$iban = (string)$entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->IBAN;
			$gegenBLZ = $this->extractBLZFromIBAN($iban);
		}
		if ($gegenBLZ !== '') {
			$fields['30'] = $gegenBLZ;
		}

		// ?31 Kontonummer Gegenseite
		$gegenKonto = '';
		if (isset($entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->IBAN)) {
			$iban = (string)$entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->IBAN;
			$gegenKonto = $this->extractKontoFromIBAN($iban);
		} elseif (isset($entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->IBAN)) {
			$iban = (string)$entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->IBAN;
			$gegenKonto = $this->extractKontoFromIBAN($iban);
		}
		if ($gegenKonto !== '') {
			$fields['31'] = $gegenKonto;
		}

		// ?32-?33 Name Auftraggeber/Empfänger (2x27 Zeichen)
		$gegenName = '';
		if (isset($entry->NtryDtls->TxDtls->RltdPties->Dbtr->Nm)) {
			$gegenName = (string)$entry->NtryDtls->TxDtls->RltdPties->Dbtr->Nm;
		} elseif (isset($entry->NtryDtls->TxDtls->RltdPties->Cdtr->Nm)) {
			$gegenName = (string)$entry->NtryDtls->TxDtls->RltdPties->Cdtr->Nm;
		}

		if ($gegenName !== '') {
			$nameLines = str_split($gegenName, 27);
			$fields['32'] = $nameLines[0];
			if (isset($nameLines[1])) {
				$fields['33'] = $nameLines[1];
			}
		}

		// ?34 Textschlüsselergänzung (optional)
		// ?60-?63 SEPA-spezifisch (optional)
		if (isset($entry->NtryDtls->TxDtls->Refs->EndToEndId)) {
			$fields['60'] = $this->truncate((string)$entry->NtryDtls->TxDtls->Refs->EndToEndId, 27);
		}

		if (isset($entry->NtryDtls->TxDtls->Refs->MndtId)) {
			$fields['61'] = $this->truncate((string)$entry->NtryDtls->TxDtls->Refs->MndtId, 27);
		}

		if (isset($entry->NtryDtls->TxDtls->Cdtr->Id->OrgId->Othr->Id)) {
			$fields['62'] = $this->truncate((string)$entry->NtryDtls->TxDtls->Cdtr->Id->OrgId->Othr->Id, 27);
		}

		if (isset($entry->NtryDtls->TxDtls->RmtInf->Strd->CdtrRefInf->Ref)) {
			$fields['63'] = $this->truncate((string)$entry->NtryDtls->TxDtls->RmtInf->Strd->CdtrRefInf->Ref, 27);
		}

		// Strukturiertes Format aufbauen mit max. 65 Bytes pro Zeile
		$result = [];
		$currentLine = ':86:';
		$firstField = true;

		foreach ($fields as $code => $value) {
			$fieldText = ($firstField ? $gvc . '?' : '?') . str_pad($code, 2, '0', STR_PAD_LEFT) . $value;

			// Prüfen ob Feld in aktuelle Zeile passt (65 Bytes inkl. Tag)
			if (strlen($currentLine . $fieldText) > 65) {
				// Aktuelle Zeile speichern und neue Zeile beginnen
				$result[] = $currentLine;
				$currentLine = $fieldText;
			} else {
				$currentLine .= $fieldText;
			}

			$firstField = false;
		}

		// Letzte Zeile hinzufügen
		if ($currentLine !== ':86:') {
			$result[] = $currentLine;
		}

		return implode("\r\n", $result);
	}

	private function truncate($text, $length)
	{
		if (strlen($text) <= $length) {
			return $text;
		}
		return substr($text, 0, $length);
	}

	private function extractGVC($entry)
	{
		// Mapping-Tabelle Domain/Family/SubFamily zu GVC
		$domain = '';
		$family = '';
		$subFamily = '';

		if (isset($entry->BkTxCd->Domn->Cd)) {
			$domain = (string)$entry->BkTxCd->Domn->Cd;
		}

		if (isset($entry->BkTxCd->Domn->Fmly->Cd)) {
			$family = (string)$entry->BkTxCd->Domn->Fmly->Cd;
		}

		if (isset($entry->BkTxCd->Domn->Fmly->SubFmlyCd)) {
			$subFamily = (string)$entry->BkTxCd->Domn->Fmly->SubFmlyCd;
		}

		// GVC-Mapping basierend auf Domain/Family/SubFamily
		$key = "{$domain}|{$family}|{$subFamily}";

		$mapping = [
			// 006 - Kreditkarte
			'PMNT|CCRD|POSC' => '006',
			// 008 - Dauerauftrag (Überweisung)
			'PMNT|ICDT|STDO' => '008',
			// 052 - Dauerauftrag (Lastschrift)
			'PMNT|RCDT|STDO' => '052',
			// 082 - Einzahlung
			'PMNT|CNTR|CDPT' => '082',
			// 083 - Bargeldauszahlung
			'PMNT|CNTR|CWDL' => '083',
			// 084 - Überweisung (PayDirekt)
			'PMNT|RDDT|OODD' => '084',
			// 087 - Eilüberweisung Soll
			'PMNT|ICDT|SDVA' => '087',
			// 088 - Eilüberweisung Haben
			'PMNT|RCDT|SDVA' => '088',
			// 101 - Inhaberscheck
			'PMNT|ICHQ|CCHQ' => '101',
			// 102 - Orderscheck
			'PMNT|ICHQ|ORCQ' => '102',
			// 103 - Reisescheck (gleiche Kombination wie 101)
			// 104 - SEPA Firmenlastschrift B2B Soll
			'PMNT|RDDT|BBDD' => '104',
			// 105 - SEPA Basislastschrift CORE Soll
			'PMNT|RDDT|ESDD' => '105',
			// 106 - POS-Kartenzahlung / Card Clearing
			'PMNT|CCRD|POSD' => '106',
			'PMNT|CCRD|CWDL' => '106',
			'PMNT|CCRD|OTHR' => '106',
			'PMNT|CCRD|SMRT' => '106',
			'PMNT|MCRD|CHRG' => '106',
			// 107 - Lastschrift POS/ELV
			// 'PMNT|CCRD|OTHR' => '107', // Konflikt mit 106
			// 108 - SEPA Lastschrift-Rückgabe B2B
			'PMNT|IDDT|UPDD' => '108',
			// 109 - SEPA Lastschrift-Rückgabe CORE
			'PMNT|RDDT|UPDD' => '109',
			// 110 - SEPA Cards Clearing Rückbelastung
			'PMNT|MCRD|UPCT' => '110',
			// 111 - Rückrechnung Scheck
			'PMNT|ICHQ|UPCQ' => '111',
			// 112 - Zahlungsanweisung zur Verrechnung
			'PMNT|ICHQ|ESCT' => '112',
			// 116 - SEPA-Überweisung Soll
			'PMNT|ICDT|ESCT' => '116',
			// 117 - Dauerauftrag (Ausführung) - bereits als 008 definiert
			// 118 - Echtzeit-Überweisung Soll
			'PMNT|IRCT|ESCT' => '118',
			// 119 - SEPA Überweisung Spende (gleiche Kombination wie 116)
			// 122 - Währungsscheck auf Euro (gleiche Kombination wie 101)
			// 152 - SEPA-Dauerauftragsgutschrift (gleiche Kombination wie 052)
			// 153 - SEPA-Pensions-/Gehaltsgutschrift
			'PMNT|RCDT|SALA' => '153',
			// 154 - SEPA-Gutschrift VL
			// 155 - SEPA-Gutschrift Alters-VL
			// 156 - SEPA-Regierungsgutschrift (gleiche Kombination wie 166)
			// 157 - Echtzeit Gehaltsgutschrift
			'PMNT|RRCT|SALA' => '157',
			// 159 - SEPA Rückgabe/Retoure Überweisung
			'PMNT|ICDT|RRTN' => '159',
			// 160 - SEPA Echtzeit Überweisung Rückgabe
			'PMNT|IRCT|RRTN' => '160',
			'PMNT|RRCT|RRTN' => '160',
			// 161 - Echtzeit Gutschrift VL
			// 162 - Echtzeit Gutschrift AltersVL
			// 163 - Echtzeit Regierungsgutschrift
			// 164 - Echtzeit-Gutschrift
			// 165 - Echtzeit-Gutschrift Spende
			// 166 - SEPA-Gutschrift
			'PMNT|RCDT|ESCT' => '166',
			// 167 - SEPA Gutschrift mit Prüfziffer
			// 168 - SEPA Echtzeitüberweisung Haben
			'PMNT|RRCT|ESCT' => '168',
			// 169 - SEPA-Gutschrift Spende
			// 170 - Scheckeinreichung
			'PMNT|RCHQ|URCQ' => '170',
			// 171 - Lastschrift Haben
			'PMNT|IDDT|ESDD' => '171',
			// 174 - SEPA-Firmenlastschrift Haben
			'PMNT|IDDT|BBDD' => '174',
			// 177 - Überweisung (Direct Banking) - gleiche Kombination wie 116
			// 181 - SEPA Lastschrift-Rückgabe CORE Wiedergutschrift
			// Konflikt: bereits 'PMNT|RDDT|UPDD' => '109'
			// 182 - SEPA Cards Clearing Wiedergutschrift
			'PMNT|CCRD|RIMB' => '182',
			// 183 - Scheckrückgabe
			'PMNT|RCHQ|UPCQ' => '183',
			// 184 - SEPA Lastschrift-Rückgabe B2B Wiedergutschrift
			// Konflikt: bereits 'PMNT|RDDT|UPDD' => '109'
			// 185 - Scheckbelastung Sammler
			// 188 - SEPA Echtzeitüberweisung Sammler Soll
			// 189 - SEPA Echtzeitüberweisung Sammler Haben
			// 190 - POS Kartenzahlung Sammler
			// 191 - SEPA-Überweisungsdatei Sammler
			// 192 - SEPA-Basislastschrift Sammler
			// 193 - SEPA reversal
			'PMNT|IDDT|RCDD' => '193',
			// 194 - SEPA-Gutschrift Sammler
			// 195 - SEPA-Basislastschrift Sammler Soll
			// 196 - SEPA-Firmenlastschrift Sammler Haben
			// 197 - SEPA-Firmenlastschrift Sammler Soll
			// 198 - POS Gutschrift
			'PMNT|MCRD|POSP' => '198',
			// 199 - SEPA Cards Clearing Reversal
			'PMNT|MCRD|DAJT' => '199',
			// 201/202 - Auslandszahlungsverkehr
			'PMNT|ICDT|XBCT' => '201',
			'PMNT|RCDT|XBCT' => '202',
			'PMNT|ICDT|XRTN' => '202',
			// 205 - Aval
			'TRAD|GUAR|OTHR' => '205',
			// 212 - Auslandsdauerauftrag
			'PMNT|ICDT|XBST' => '212',
			// 216 - Wechsel-Inkasso Import
			'PMNT|DRFT|STAM' => '216',
			// 217 - Wechsel-Inkasso Export
			'PMNT|DRFT|STLR' => '217',
			// 224 - Wechseldiskontierung
			'PMNT|CNTR|FCDP' => '224',
			// 302 - Zinsen/Dividende
			'SECU|CUST|DVCA' => '302',
			// 303 - Effekten
			'SECU|SETT|TRAD' => '303',
			// 311 - Derivatebuchung
			'DERV|OTHR|OTHR' => '311',
			// 321 - Depotpreise
			'SECU|CUST|CHRG' => '321',
			// 411 - Devisenkassa-Kauf
			'FORX|SPOT|OTHR' => '411',
			// 412 - Devisenkassa-Verkauf (gleiche Kombination)
			// 413 - Devisentermin-Kauf
			'FORX|FWRD|OTHR' => '413',
			// 414 - Devisentermin-Verkauf (gleiche Kombination)
			// 423/424 - Edelmetall-Abrechnung
			'PMET|SPOT|OTHR' => '423',
			// 801 - Kartenpreis
			// 805 - Abschluss
			'ACMT|OPCL|ACCC' => '805',
			// 806 - Preis für Kontoauszug
			// 807 - Vorverfügungspreis
			// 808 - Gebühren
			'ACMT|MDOP|CHRG' => '808',
			'ACMT|MCOP|CHRG' => '808',
			// 809 - Provision
			'ACMT|MDOP|COMM' => '809',
			'ACMT|MCOP|COMM' => '809',
			'TRAD|MCOP|COMM' => '809',
			'TRAD|MDOP|COMM' => '809',
			// 811 - Kreditprovision
			'LDAS|MCOP|CHRG' => '811',
			'LDAS|MDOP|CHRG' => '811',
			// 814 - Zinsen
			'ACMT|MCOP|INTR' => '814',
			'ACMT|MDOP|INTR' => '814',
			// 818 - Belastung
			'PMNT|MDOP|OTHR' => '818',
			// 819 - Gutschrift
			'PMNT|MCOP|OTHR' => '819',
			// 820 - Saldo/Übertrag
			'PMNT|RCDT|BOOK' => '820',
			'PMNT|ICDT|BOOK' => '820',
			// 823 - Termingeld
			'LDAS|FTDP|DPST' => '823',
			'LDAS|FTDP|RPMT' => '823',
			// 829 - Sparplan
			'LDAS|FTDP|RPMT' => '829',
			// 833 - Cash Pooling/Übertrag
			'CAMT|CAPL|OTHR' => '833',
			'CAMT|ACCB|ZABA' => '833',
			// 835 - Sonstiges
			'XTND|NTAV|NTAV' => '835',
			'PMNT|OTHR|OTHR' => '835',
			// 899 - Storno
			'ACMT|ACOP|PSTE' => '899',
		];

		if (isset($mapping[$key])) {
			return $mapping[$key];
		}

		return '999'; // Unbekannt
	}

	private function extractBLZFromIBAN($iban)
	{
		$iban = str_replace(' ', '', $iban);
		if (strlen($iban) === 22 && substr($iban, 0, 2) === 'DE') {
			return substr($iban, 4, 8);
		}
		return '';
	}

	private function extractKontoFromIBAN($iban)
	{
		$iban = str_replace(' ', '', $iban);
		if (strlen($iban) === 22 && substr($iban, 0, 2) === 'DE') {
			return substr($iban, 12, 10);
		}
		return '';
	}

	private function formatDate($date)
	{
		// Von YYYY-MM-DD zu YYMMDD
		$dt = new DateTime($date);
		return $dt->format('ymd');
	}

	private function formatAmount($amount)
	{
		// Betrag formatieren: Komma statt Punkt, ohne Tausendertrennzeichen
		$formatted = number_format((float)$amount, 2, ',', '');
		return str_replace('.', ',', $formatted);
	}

	private function extractBLZKontoFromIBAN($iban)
	{
		// IBAN Format: DEpp bbbb bbbb cccc cccc cc
		// pp = Prüfziffer, b = BLZ (8 Stellen), c = Kontonummer (10 Stellen)

		// Leerzeichen entfernen
		$iban = str_replace(' ', '', $iban);

		// Deutsche IBAN hat 22 Zeichen
		if (strlen($iban) !== 22 || substr($iban, 0, 2) !== 'DE') {
			return $iban; // Fallback: Original IBAN zurückgeben
		}

		// BLZ: Zeichen 5-12 (8 Stellen)
		$blz = substr($iban, 4, 8);

		// Kontonummer: Zeichen 13-22 (10 Stellen)
		$konto = substr($iban, 12, 10);

		// Format: BLZ/Kontonummer
		return "{$blz}/{$konto}";
	}

	public function convertDirectory($directory)
	{
		// Unterordner erstellen falls nicht vorhanden
		$saveDir = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'save';
		$errorDir = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'error';

		if (!is_dir($saveDir)) {
			mkdir($saveDir, 0777, true);
		}

		if (!is_dir($errorDir)) {
			mkdir($errorDir, 0777, true);
		}

		$results = [];

		// Alle XML-Dateien im Verzeichnis durchlaufen
		$iterator = new DirectoryIterator($directory);

		foreach ($iterator as $fileInfo) 
		{
			if ($fileInfo->isDot() || !$fileInfo->isFile()) {
				continue;
			}

			if (strtolower($fileInfo->getExtension()) !== 'xml') {
				continue;
			}

			$xmlFile        = $fileInfo->getPathname();
			$filename       = $fileInfo->getFilename();
			$nameWithoutExt = $fileInfo->getBasename('.xml');
			$staFile        = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $nameWithoutExt . '.STA';

			try {
				// Konvertierung durchführen
				$this->convertFile($xmlFile, $staFile);

				// Original nach save verschieben
				$saveFile = $saveDir . DIRECTORY_SEPARATOR . $filename;
				rename($xmlFile, $saveFile);

				$results[] = [
					'xml'   => $filename,
					'sta'   => basename($staFile),
					'error' => 'OK'
				];
			} 
			catch (Exception $e) 
			{
				// Bei Fehler nach error verschieben
				$errorFile = $errorDir . DIRECTORY_SEPARATOR . $filename;
				rename($xmlFile, $errorFile);

				$results[] = [
					'xml' => $filename,
					'error' => $e->getMessage()
				];
			}
		}

		return $results;
	}
}
