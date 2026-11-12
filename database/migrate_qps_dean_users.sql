-- Dean authorization registry for COE workflow.
-- Run ONLY after selecting the restored test database (e.g. hccweb_coe_test).
-- Does not modify the legacy staff master or staff passwords.

CREATE TABLE IF NOT EXISTS qps_dean_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_code VARCHAR(32) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qps_dean_users_staff_code (staff_code),
    KEY idx_qps_dean_users_active_staff (is_active, staff_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed only codes that currently exist in the existing staff master.
-- UPPER comparison handles mixed-case legacy codes such as trs1997g01.
INSERT INTO qps_dean_users (staff_code, is_active)
SELECT s.STAFF_CODE, 1
FROM pr_x_xxxx_staf_prof_mast s
WHERE UPPER(s.STAFF_CODE) IN (
    'TCS2013M09',
    'TCH2014G01',
    'TZO2018G01',
    'TEL2014G01',
    'TEL2007G03',
    'TRS1997G01',
    'TCO2022G01'
)
ON DUPLICATE KEY UPDATE is_active = VALUES(is_active);

-- Verification: every requested code is shown, including codes not found in staff master.
SELECT requested.staff_code AS requested_staff_code,
       s.STAFF_CODE AS matched_staff_master_code,
       d.is_active AS dean_access_active
FROM (
    SELECT 'TCS2013M09' AS staff_code UNION ALL
    SELECT 'TCH2014G01' UNION ALL
    SELECT 'TZO2018G01' UNION ALL
    SELECT 'TEL2014G01' UNION ALL
    SELECT 'TEL2007G03' UNION ALL
    SELECT 'trs1997g01' UNION ALL
    SELECT 'TCO2022G01'
) requested
LEFT JOIN pr_x_xxxx_staf_prof_mast s
       ON UPPER(s.STAFF_CODE) = UPPER(requested.staff_code)
LEFT JOIN qps_dean_users d
       ON UPPER(d.staff_code) = UPPER(requested.staff_code)
ORDER BY requested.staff_code;
