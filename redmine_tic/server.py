from fastapi import FastAPI, HTTPException

app = FastAPI(title="Redmine TIC legacy webhook")


@app.get("/health")
async def health():
    return {
        "ok": False,
        "status": "deprecated",
        "message": "Redmine TIC ahora persiste datos via Laravel MVC y base de datos.",
    }


@app.post("/webhook")
async def webhook():
    raise HTTPException(
        status_code=410,
        detail="Webhook legacy deshabilitado. Usa el flujo Laravel MVC/BD de Redmine TIC.",
    )
