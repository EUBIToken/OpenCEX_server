<?php
//OpenCEX upgrades
return ["INSERT INTO Misc (Kei, Val) VALUES ('testupgradesuccess', '1');",
"CREATE TABLE IF NOT EXISTS WorkerTasks (Id BIGINT PRIMARY KEY AUTO_INCREMENT NOT NULL, URL VARCHAR(255), LastTouched BIGINT, Status BIT);",
"ALTER TABLE WorkerTasks ADD URL2 VARCHAR(255) NOT NULL;",
"ALTER TABLE WorkerTasks MODIFY COLUMN URL VARCHAR(255) NOT NULL",
"CREATE TABLE IF NOT EXISTS HistoricalPrices (Timestamp BIGINT NOT NULL UNIQUE, Pri VARCHAR(255) NOT NULL, Sec VARCHAR(255) NOT NULL, Open VARCHAR(255) NOT NULL, High VARCHAR(255) NOT NULL, Low VARCHAR(255) NOT NULL, Close VARCHAR(255) NOT NULL);",
"ALTER TABLE WorkerTasks MODIFY COLUMN Status TINYINT NOT NULL;",
"CREATE TABLE IF NOT EXISTS Nonces (Blockchain BIGINT NOT NULL, Address VARCHAR(64) NOT NULL, ExpectedValue BIGINT NOT NULL);"];
?>