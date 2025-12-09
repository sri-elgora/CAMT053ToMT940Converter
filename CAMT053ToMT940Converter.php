<?php
  
  require_once 'class/CAMT053ToMT940Converter.php';

  # Parameter = Lieferant
  if (!isset($argv[1]))
  {
	echo "Aufruf: php CAMT053ToMT940Converter.php \"C:\MeinVerzeichnis\"" . PHP_EOL;
	exit;
  }
  
  $data_dir = $argv[1];
  
  if (!file_exists($data_dir))
  {
	echo "Verzeichnis nicht gefunden: " . $data_dir . PHP_EOL;
	exit;
  }

  // Verwendung:
  echo date('Y-m-d H:i:s') . " Start Konvertierung\n";
  $converter = new CAMT053ToMT940Converter();
  try 
  {
    $aFiles = $converter->convertDirectory($data_dir);
    if (is_array($aFiles) and count($aFiles) > 0)
    {  
      foreach ($aFiles as $sFile) 
      {
        echo "Erstellt: " . $sFile['xml'] . ' ' . $sFile['error'] . "\n";
      }
    }
    else
    {
      echo "Nichts zu tun.\n";
    }
  } 
  catch (Exception $e) 
  {
    echo "Fehler: " . $e->getMessage();
  }
  echo date('Y-m-d H:i:s') . " Ende Konvertierung\n";  