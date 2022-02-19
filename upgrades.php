<?php
//OpenCEX upgrades
return ["INSERT INTO Misc (Kei, Val) VALUES ('testupgradesuccess', '1');",
"CREATE TABLE IF NOT EXISTS WorkerTasks (Id BIGINT PRIMARY KEY AUTO_INCREMENT NOT NULL, URL VARCHAR(255), LastTouched BIGINT, Status BIT);"];
?>