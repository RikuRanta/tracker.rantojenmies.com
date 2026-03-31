-- Uudelleenlaskennan valmistelu + benchmark-laskurit
-- 1) Aseta parametrit (@imei, @from_ts)
-- 2) Aja tämä SQL
-- 3) Aja process.php kerran (tai useita kertoja mittaukseen)
-- 4) Aja AFTER-osio lopuksi

SET @imei = '868683020222950'; -- Aseta tähän uudelleenlaskettavan laitteen IMEI
SET @from_ts = '2016-01-01 00:00:00'; -- Aseta tähän uudelleenlaskennan aloitusajankohta (esim. vanhimpien datapisteiden timestamp)

-- ======================================================
-- BEFORE: mittarit ennen nollausta
-- ======================================================
SELECT 'BEFORE Data (raw, unprocessed)' AS Metric,
			 COUNT(*) AS Cnt
FROM Data
WHERE Processed = '0';

SELECT 'BEFORE DataStaging (target IMEI, unprocessed)' AS Metric,
			 COUNT(*) AS Cnt
FROM DataStaging
WHERE Imei = @imei
	AND Processed = '0';

SELECT 'BEFORE DataArchive (target IMEI, since from_ts)' AS Metric,
			 COUNT(*) AS Cnt
FROM DataArchive
WHERE Imei = @imei
	AND `Timestamp` > @from_ts;

SELECT 'BEFORE Path visible/group0 (target IMEI)' AS Metric,
			 COUNT(*) AS Cnt
FROM Path
WHERE Imei = @imei
	AND `Group` = 0;

-- ======================================================
-- RESET: valmistellaan data uudelleenlaskentaan
-- ======================================================

-- Poistetaan arkistosta from_ts jälkeinen data valitulle IMEI:lle
DELETE FROM DataArchive
WHERE `Timestamp` > @from_ts
	AND Imei = @imei;

-- Palautetaan staging-rivit jonoon
UPDATE DataStaging
SET Processed = '0'
WHERE `Timestamp` > @from_ts
	AND Imei = @imei;

-- Poistetaan matkat, joita ei enää ole
DELETE p
FROM Path p
WHERE p.Imei = @imei
	AND p.`Group` = 0
	AND (p.Start >= @from_ts OR IFNULL(p.End, p.Start) >= @from_ts)
	AND NOT EXISTS (
		SELECT 1
		FROM DataArchive da
		WHERE da.Imei = p.Imei
			AND da.Path_Id = p.Id
	);

-- Päivitetään laitteen tila
UPDATE Devices
SET LastUpdated = @from_ts,
		DeleteNewer = '1'
WHERE Imei = @imei;

-- ======================================================
-- AFTER-RESET: tarkista että reset meni läpi
-- ======================================================
SELECT 'AFTER_RESET DataStaging (target IMEI, unprocessed)' AS Metric,
			 COUNT(*) AS Cnt
FROM DataStaging
WHERE Imei = @imei
	AND Processed = '0';

SELECT 'AFTER_RESET DataArchive (target IMEI, since from_ts)' AS Metric,
			 COUNT(*) AS Cnt
FROM DataArchive
WHERE Imei = @imei
	AND `Timestamp` > @from_ts;

-- ======================================================
-- AFTER-PROCESS: aja process.php ja suorita nämä lopuksi
-- ======================================================
-- SELECT 'AFTER_PROCESS Data (raw, unprocessed)' AS Metric, COUNT(*) AS Cnt FROM Data WHERE Processed = '0';
-- SELECT 'AFTER_PROCESS DataStaging (target IMEI, unprocessed)' AS Metric, COUNT(*) AS Cnt FROM DataStaging WHERE Imei = @imei AND Processed = '0';
-- SELECT 'AFTER_PROCESS DataArchive (target IMEI, since from_ts)' AS Metric, COUNT(*) AS Cnt FROM DataArchive WHERE Imei = @imei AND `Timestamp` > @from_ts;
-- SELECT 'AFTER_PROCESS Path visible/group0 (target IMEI)' AS Metric, COUNT(*) AS Cnt FROM Path WHERE Imei = @imei AND `Group` = 0;

-- ======================================================
-- LIVE ETA (korjattu): perustuu ajon aikana vähenevään jonoon
-- ======================================================
-- 1) Ota ensimmäinen näyte (samassa SQL-istunnossa):
SET @eta_t1 = NOW();
SET @eta_r1 = (SELECT COUNT(*) FROM DataStaging WHERE Imei = @imei AND Processed = '0');

-- 2) Odota esim. 2-5 min ja aja seuraavat rivit:
-- SET @eta_t2 = NOW();
-- SET @eta_r2 = (SELECT COUNT(*) FROM DataStaging WHERE Imei = @imei AND Processed = '0');

-- 3) ETA-laskelma (ajankohta, rivinopeus, arvioitu jäljellä oleva aika):
-- SELECT
-- 	@imei AS Imei,
-- 	@eta_t1 AS Sample1Time,
-- 	@eta_r1 AS Sample1Remaining,
-- 	@eta_t2 AS Sample2Time,
-- 	@eta_r2 AS Sample2Remaining,
-- 	TIMESTAMPDIFF(SECOND, @eta_t1, @eta_t2) AS SampleSeconds,
-- 	ROUND((@eta_r1 - @eta_r2) / NULLIF(TIMESTAMPDIFF(SECOND, @eta_t1, @eta_t2) / 60, 0), 1) AS RowsPerMin,
-- 	CASE
-- 		WHEN (@eta_r1 - @eta_r2) <= 0 THEN NULL
-- 		ELSE ROUND(@eta_r2 / ((@eta_r1 - @eta_r2) / NULLIF(TIMESTAMPDIFF(SECOND, @eta_t1, @eta_t2) / 60, 0)), 1)
-- 	END AS EtaMin;