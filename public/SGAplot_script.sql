CREATE OR REPLACE PROCEDURE SGAplot(p_critico IN NUMBER DEFAULT 85) AUTHID DEFINER
IS
  -- Umbral efectivo (validado 0..100)
  v_critico NUMBER := NVL(p_critico, 85);

  -- Métricas del buffer cache
  v_block_size_bytes  NUMBER;
  v_total_buffers     NUMBER;
  v_total_bytes       NUMBER;
  v_free_bufs         NUMBER;
  v_used_bytes        NUMBER;
  v_used_pct          NUMBER;

  -- (Opcional) Tamaño reportado por V$SGAINFO
  v_buffer_cache_size_sgainfo  NUMBER;
BEGIN
  ----------------------------------------------------------------------
  -- 0) Validación del parámetro
  ----------------------------------------------------------------------
  IF v_critico < 0 OR v_critico > 100 THEN
    RAISE_APPLICATION_ERROR(-20001, 'p_critico debe estar entre 0 y 100');
  END IF;

  ----------------------------------------------------------------------
  -- 1) Tamaño de bloque y cantidad de buffers en el cache
  ----------------------------------------------------------------------
  SELECT TO_NUMBER(value)
  INTO   v_block_size_bytes
  FROM   v$parameter
  WHERE  name = 'db_block_size';

  SELECT NVL(SUM(buffers),0)
  INTO   v_total_buffers
  FROM   v$buffer_pool;

  -- Capacidad total del buffer cache (bytes)
  v_total_bytes := NVL(v_total_buffers,0) * NVL(v_block_size_bytes,0);

  ----------------------------------------------------------------------
  -- 2) Buffers libres (estado FREE) para estimar "usado" en el cache
  ----------------------------------------------------------------------
  SELECT COUNT(*)
  INTO   v_free_bufs
  FROM   v$bh
  WHERE  status = 'free';

  v_used_bytes := GREATEST(v_total_bytes - (v_free_bufs * v_block_size_bytes), 0);

  v_used_pct := CASE
                  WHEN v_total_bytes > 0
                    THEN (v_used_bytes / v_total_bytes) * 100
                  ELSE 0
                END;

  ----------------------------------------------------------------------
  -- 3) (Opcional/validación) Máximo del buffer desde V$SGAINFO
  ----------------------------------------------------------------------
  BEGIN
    SELECT bytes
    INTO   v_buffer_cache_size_sgainfo
    FROM   v$sgainfo
    WHERE  name = 'Buffer Cache Size';
  EXCEPTION
    WHEN NO_DATA_FOUND THEN
      v_buffer_cache_size_sgainfo := NULL;
  END;

  ----------------------------------------------------------------------
  -- 4) Si el uso del buffer cache es crítico, registrar alertas
  --    Una fila por CADA sesión activa (quién está y qué hace)
  ----------------------------------------------------------------------
  IF v_used_pct >= v_critico THEN
    FOR s IN (
      SELECT s.sid,
             s.serial#,
             s.username,
             s.program,
             s.sql_id,
             s.event
      FROM   v$session s
      WHERE  s.username IS NOT NULL
      AND    s.status   = 'ACTIVE'
    )
    LOOP
      INSERT INTO alertas (dia, hora, usuario, proceso, tipo)
      VALUES (
        TRUNC(SYSDATE),
        TO_CHAR(SYSDATE, 'HH24:MI:SS'),
        NVL(s.username, SYS_CONTEXT('USERENV','SESSION_USER')),
        'SGA_MONITOR Buffer='||TO_CHAR(ROUND(v_used_pct,2))||'%'||
        ' | UMBRAL='||TO_CHAR(v_critico)||'%'||
        ' | SID='||s.sid||','||s.serial#||
        ' | PROG='||NVL(s.program,'?')||
        ' | SQL_ID='||NVL(s.sql_id,'?')||
        ' | EVENT='||NVL(s.event,'?'),
        'SQL'
      );
    END LOOP;
    COMMIT;
  END IF;

EXCEPTION
  WHEN OTHERS THEN
    ROLLBACK;
    RAISE;
END;
/
SHOW ERRORS PROCEDURE SGAplot;


CREATE TABLE alertas (
    id_alerta   NUMBER GENERATED ALWAYS AS IDENTITY,
    dia         DATE NOT NULL,
    hora        VARCHAR2(8) NOT NULL,
    usuario     VARCHAR2(30),
    proceso     VARCHAR2(4000),
    tipo        VARCHAR2(20),
    fecha_reg   TIMESTAMP DEFAULT SYSTIMESTAMP,
    CONSTRAINT pk_alertas PRIMARY KEY (id_alerta)
);

-- Índices útiles
CREATE INDEX ix_alertas_dia      ON alertas (dia);
CREATE INDEX ix_alertas_fechareg ON alertas (fecha_reg);
