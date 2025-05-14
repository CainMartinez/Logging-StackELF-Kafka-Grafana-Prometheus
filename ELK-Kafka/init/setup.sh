#!/bin/bash

# Esperar a que Elasticsearch esté disponible
echo "Esperando a que el clúster de Elasticsearch esté al menos en YELLOW..."
until curl -s -u elastic:Password1! \
    "http://elasticsearch:9200/_cluster/health?wait_for_status=yellow&timeout=50s" \
    | grep -q '"status":"yellow"\|"status":"green"' ; do
    echo "  aún no está listo (red), reintentando en 5s…"
    sleep 5
done
echo "Cluster listo."

# Elimina el token existente (si existe)
echo "Borrando token existente (si existe)..."
curl -s -u elastic:Password1! -X DELETE \
    "http://elasticsearch:9200/_security/service/elastic/kibana/credential/token/kibana-token" \
    && echo "OK" || echo "No existía token previo."

# Crea un nuevo token para la cuenta de servicio de Kibana
echo "Creando token nuevo..."
RESPONSE=$(curl -s -X PUT -u elastic:Password1! \
    "http://elasticsearch:9200/_security/service/elastic/kibana/credential/token/kibana-token")

echo ">> Response completa: $RESPONSE"

# Extrae el token
TOKEN=$(echo $RESPONSE | grep -o '"value":"[^"]*"' | cut -d'"' -f4)
echo ">> Token extraído: $TOKEN"

# Comprueba si se obtuvo el token, si no aborta y no actualiza el archivo de configuración
if [ -z "$TOKEN" ]; then
    echo "¡Error! No se obtuvo token. Abortando."
    exit 1
fi

# Actualiza archivo de configuración de Kibana
echo "Guardando token en el archivo de configuración..."
cat > /config/kibana.yml << EOF
server.name: kibana
server.host: "0.0.0.0"

elasticsearch.hosts: ["http://elasticsearch:9200"]
elasticsearch.serviceAccountToken: "$TOKEN"

# Claves de encriptación
xpack.security.encryptionKey: "practicaGestfy_clave_segura_01-segura"
xpack.encryptedSavedObjects.encryptionKey: "practicaGestfy_clave_segura_02-segura"
xpack.reporting.encryptionKey: "practicaGestfy_clave_segura_03-segura"
EOF

echo "Configuración completada."