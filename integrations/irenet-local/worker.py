#!/usr/bin/env python3
"""
IRENET -> VITI: outbound-only local Ollama connector (standard Python library).

Requires:
  VITI_IRENET_BRIDGE_TOKEN: same random secret configured in Render.
Optional:
  VITI_API_BASE=https://viti-core-api-alexby2g.onrender.com/api/v1
  VITI_LOCAL_OLLAMA_MODEL=qwen2.5:7b

Do not store passwords or tokens in this file; set them in the PowerShell
session that launches the worker. Stop with Ctrl+C.
"""
import json
import os
import ssl
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen, build_opener, HTTPSHandler, HTTPRedirectHandler

API_BASE = os.environ.get(
    "VITI_API_BASE",
    "https://viti-core-api-alexby2g.onrender.com/api/v1",
).rstrip("/")
OLLAMA_URL = "http://127.0.0.1:11434/api/chat"
MODEL = os.environ.get("VITI_LOCAL_OLLAMA_MODEL", "qwen2.5:7b")
TOKEN = os.environ.get("VITI_IRENET_BRIDGE_TOKEN", "")
CONTEXT = (
    "Eres IRENET, asistente de AGR 006 de VITI. "
    "Responde en español y ayuda a analizar, planear y explicar sistemas "
    "web, arquitectura y código. Solo puedes generar texto. "
    "No puedes ejecutar comandos, acceder a datos privados, editar archivos "
    "ni afirmar que has realizado acciones. Nunca inventes datos internos de VITI."
)


def ensure_safe_config():
    location = urlsplit(API_BASE)
    if location.scheme != "https" or location.hostname != "viti-core-api-alexby2g.onrender.com":
        raise RuntimeError("La API debe ser la dirección HTTPS oficial de VITI en Render.")
    if len(TOKEN) < 32:
        raise RuntimeError("Configura un secreto VITI_IRENET_BRIDGE_TOKEN de al menos 32 caracteres.")
    if not MODEL or len(MODEL) > 80:
        raise RuntimeError("Modelo Ollama inválido.")


def api_call(method, path, payload=None):
    headers = {
        "Accept": "application/json",
        "X-Irenet-Bridge-Token": TOKEN,
        "User-Agent": "IRENET-VITI-Local-Bridge/1.0",
    }
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = Request(API_BASE + path, data=data, headers=headers, method=method)
    # SSL certificate verification uses Python's normal trusted CA store.
    # Never forward the private bridge token to a redirected host.
    class NoRedirect(HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, msg, headers, newurl):
            return None

    opener = build_opener(HTTPSHandler(context=ssl.create_default_context()), NoRedirect())
    with opener.open(req, timeout=85) as response:
        if response.status == 204:
            return None
        return json.loads(response.read(7000).decode("utf-8"))


def ask_ollama(question):
    payload = {
        "model": MODEL,
        "stream": False,
        "messages": [
            {"role": "system", "content": CONTEXT},
            {"role": "user", "content": question},
        ],
        "options": {"temperature": 0.4, "num_predict": 1800},
    }
    req = Request(
        OLLAMA_URL,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urlopen(req, timeout=240) as response:
        answer = json.loads(response.read(150000).decode("utf-8"))
    result = (answer.get("message") or {}).get("content", "")
    result = result.strip() if isinstance(result, str) else ""
    if not result:
        raise RuntimeError("Ollama devolvió una respuesta vacía.")
    # Keep answers under the backend limit, without silently chopping mid-word.
    if len(result) > 11900:
        prefix = result[:11800]
        shortened = prefix.rsplit("\n", 1)[0] if "\n" in prefix else prefix.rsplit(" ", 1)[0]
        return shortened + "\n\n[Respuesta extensa: el resultado supera el límite de tamaño. Solicita un análisis más específico.]"
    return result


def run():
    ensure_safe_config()
    print("IRENET conectado a VITI mediante HTTPS. Modelo:", MODEL)
    print("Consulta solo texto. Detén el servicio con Ctrl+C.")
    while True:
        try:
            job = api_call("GET", "/assistant/local/worker/next")
            if job and isinstance(job.get("id"), str) and isinstance(job.get("message"), str):
                # The worker never executes model-generated commands or tools.
                try:
                    response = ask_ollama(job["message"])
                    api_call("POST", "/assistant/local/worker/jobs/" + job["id"] + "/complete", {
                        "status": "completed",
                        "answer": response,
                    })
                    print("Respuesta enviada a VITI.")
                except Exception as exc:
                    print("Falló la consulta local:", type(exc).__name__)
                    try:
                        api_call("POST", "/assistant/local/worker/jobs/" + job["id"] + "/complete", {
                            "status": "failed",
                        })
                    except (HTTPError, URLError, TimeoutError, OSError):
                        pass
            time.sleep(4)
        except KeyboardInterrupt:
            print("\nConector detenido.")
            break
        except HTTPError as exc:
            print("VITI respondió HTTP", exc.code, "(se reintentará).")
            time.sleep(12)
        except (URLError, TimeoutError, OSError) as exc:
            print("No se pudo contactar VITI:", type(exc).__name__)
            time.sleep(12)


if __name__ == "__main__":
    try:
        run()
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        raise SystemExit(1)
