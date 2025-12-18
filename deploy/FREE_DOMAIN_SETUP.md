# ===========================================
# ҮНЭГҮЙ DOMAIN ТОХИРГОО - 3 ЭМНЭЛЭГ
# Cloudflare Tunnel ашиглан
# ===========================================

## 🆓 Үнэгүй Domain Сонголтууд

### 1. Cloudflare Tunnel (Санал болгож буй)
Автоматаар `.trycloudflare.com` subdomain өгнө:
```
venera-booking.trycloudflare.com
luxor-booking.trycloudflare.com  
khatan-booking.trycloudflare.com
```

### 2. DuckDNS (Бас үнэгүй)
```
venera-dent.duckdns.org
luxor-dent.duckdns.org
khatan-dent.duckdns.org
```

---

## 🚀 АЛХАМ 1: Cloudflared суулгах

```bash
# Ubuntu/Debian
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o cloudflared.deb
sudo dpkg -i cloudflared.deb

# Шалгах
cloudflared --version
```

## 🚀 АЛХАМ 2: Quick Tunnel (Хамгийн хурдан)

Энэ арга нь тэр даруй ажиллана, бүртгэл хэрэггүй:

```bash
# Venera эмнэлэг - 8080 порт
cloudflared tunnel --url http://localhost:8080

# Өөр терминал дээр - Luxor эмнэлэг - 8081 порт
cloudflared tunnel --url http://localhost:8081

# Өөр терминал дээр - Khatan эмнэлэг - 8082 порт
cloudflared tunnel --url http://localhost:8082
```

Гаралт:
```
2024-12-05 Your quick tunnel: https://random-words-here.trycloudflare.com
```

---

## 🚀 АЛХАМ 3: Байнгын Tunnel (Илүү найдвартай)

### 3.1 Cloudflare бүртгүүлэх
1. https://dash.cloudflare.com руу орох
2. Бүртгүүлэх (үнэгүй)

### 3.2 Tunnel үүсгэх
```bash
# Login хийх
cloudflared tunnel login

# 3 tunnel үүсгэх
cloudflared tunnel create venera
cloudflared tunnel create luxor
cloudflared tunnel create khatan
```

### 3.3 Config файл үүсгэх
```yaml
# ~/.cloudflared/config.yml
tunnel: venera
credentials-file: /root/.cloudflared/<TUNNEL_ID>.json

ingress:
  - hostname: venera-dent.trycloudflare.com
    service: http://localhost:8080
  - service: http_status:404
```

### 3.4 Systemd service үүсгэх
```bash
sudo cloudflared service install
sudo systemctl enable cloudflared
sudo systemctl start cloudflared
```

---

## 📋 DOCKER COMPOSE + CLOUDFLARED

```yaml
# docker-compose.yml дотор нэмэх
services:
  # ... existing services ...
  
  cloudflared-venera:
    image: cloudflare/cloudflared:latest
    container_name: tunnel_venera
    restart: always
    command: tunnel --no-autoupdate run --token ${CF_TUNNEL_TOKEN_VENERA}
    depends_on:
      - venera
    networks:
      - booking_network

  cloudflared-luxor:
    image: cloudflare/cloudflared:latest
    container_name: tunnel_luxor
    restart: always
    command: tunnel --no-autoupdate run --token ${CF_TUNNEL_TOKEN_LUXOR}
    depends_on:
      - luxor
    networks:
      - booking_network

  cloudflared-khatan:
    image: cloudflare/cloudflared:latest
    container_name: tunnel_khatan
    restart: always
    command: tunnel --no-autoupdate run --token ${CF_TUNNEL_TOKEN_KHATAN}
    depends_on:
      - khatan
    networks:
      - booking_network
```

---

## 🦆 ХУВИЛБАР 2: DuckDNS (Өөр нэг үнэгүй арга)

### 1. DuckDNS бүртгүүлэх
1. https://www.duckdns.org руу орох
2. GitHub/Google-ээр нэвтрэх
3. 3 subdomain үүсгэх: venera-dent, luxor-dent, khatan-dent

### 2. IP шинэчлэгч тохируулах
```bash
# Cron тохируулах (5 минут тутам IP шинэчлэх)
```

### 3. Let's Encrypt SSL
```bash
# Certbot + DuckDNS
sudo apt install certbot
sudo certbot certonly --manual --preferred-challenges dns -d venera-dent.duckdns.org
```

---

## ✅ ХАМГИЙН ХУРДАН ЭХЛҮҮЛЭХ

Одоо шууд туршихын тулд:

```bash
# Terminal 1 - Venera
cloudflared tunnel --url http://localhost:80

# Гарах URL-г хуулж авах, жишээ нь:
# https://healthy-carpet-tokyo.trycloudflare.com
```

Энэ URL-г гадна талаас нээж болно! 🎉

---

## 🔒 АЮУЛГҮЙ БАЙДАЛ

Cloudflare Tunnel-ийн давуу тал:
- ✅ Серверийн IP нуугдана
- ✅ DDoS хамгаалалт автомат
- ✅ SSL/HTTPS автомат
- ✅ Firewall дээр port нээх шаардлагагүй
- ✅ Static IP хэрэггүй
