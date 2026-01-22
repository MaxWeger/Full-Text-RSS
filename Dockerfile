# syntax=docker/dockerfile:1
FROM php:8.2-apache

# 1. Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config libonig-dev libxml2-dev libcurl4-openssl-dev libtidy-dev git ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# 2. Enable Apache modules and PHP extensions
RUN a2enmod rewrite
RUN docker-php-ext-install -j$(nproc) mbstring curl dom tidy xml

# 3. Configure Apache ports (Fly.io uses 8080)
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf

# 4. Set working directory and copy app
WORKDIR /var/www/html
COPY . /var/www/html

# 5. Create config directories
RUN mkdir -p /var/www/html/site_config/custom \
 && mkdir -p /var/www/html/site_config/standard

# 6. FORCE the FT.com config using the easiest method possible
#    We just ECHO the file content into place.
#    *** ACTION REQUIRED: PASTE YOUR COOKIE AT THE END OF THE COOKIE LINE ***
RUN echo "http_header(User-Agent): Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36\n\
http_header(Accept-Language): en-US,en;q=0.9\n\
http_header(Referer): https://www.google.com/\n\
http_header(Cookie): FTClientSessionId=c5e867e4-2020-408e-93c9-b58d871d3f42; spoor-id=c5e867e4-2020-408e-93c9-b58d871d3f42; consentDate=2026-01-21T06:32:17.210Z; FTCookieConsentGDPR=true; _csrf=iqEtFrA2jf4k0ksK5Hr-JVy4; FTSession_s=04eIIS18r0s706cObJFMpe_F0wAAAZvfQTtfw8I.MEUCIQDltpEEs7UvJj-QRr0b-Vxoar_fwM4Fvc2tNxyc6hXefQIgCcq8V3iJvxvn-Xzri3QqSxoeTzWSPo4yqjSUmTetzE0; consentUUID=d373bc1e-77b4-4a96-9dcf-915bb6a5e1f8_28_31_41_45; skipPasskeySetup=true; FTConsent=behaviouraladsOnsite%3Aon%2CcookiesOnsite%3Aon%2CcookiesUseraccept%3Aon%2CdemographicadsOnsite%3Aon%2CenhancementByemail%3Aon%2CenhancementByfax%3Aoff%2CenhancementByphonecall%3Aon%2CenhancementBypost%3Aon%2CenhancementBysms%3Aoff%2CmarketingByemail%3Aon%2CmarketingByfax%3Aoff%2CmarketingByphonecall%3Aon%2CmarketingBypost%3Aon%2CmarketingBysms%3Aoff%2CmembergetmemberByemail%3Aoff%2CpermutiveadsOnsite%3Aon%2CpersonalisedmarketingOnsite%3Aon%2CprogrammaticadsOnsite%3Aon%2CrecommendedcontentOnsite%3Aon; _gcl_au=1.1.1319265976.1768977157; OriginalReferer=Direct; FtComEntryPoint=/content/9904baa7-d90b-4170-898d-d5ac8bebe466; ft-access-decision-policy=GRANTED_ZEPHR_REG_3_30; zit.data.toexclude=0; _sxh=1908,; _sxo={\"R\":0,\"tP\":0,\"tM\":0,\"sP\":0,\"sM\":0,\"dP\":0,\"dM\":0,\"dS\":0,\"tS\":0,\"cPs\":0,\"lPs\":[],\"sSr\":0,\"sWids\":[],\"wN\":0,\"cdT\":0,\"F\":1,\"RF\":1,\"w\":0,\"SFreq\":0,\"last_wid\":0,\"bid\":1036,\"accNo\":\"\",\"clientId\":\"\",\"isEmailAud\":0,\"isPanelAud\":0,\"hDW\":0,\"isRegAud\":0,\"isExAud\":0,\"isDropoff\":0,\"devT\":4,\"exPW\":0,\"Nba\":-1,\"userName\":\"\",\"dataLayer\":\"\",\"localSt\":\"\",\"emailId\":\"\",\"emailTag\":\"\",\"subTag\":\"\",\"lVd\":\"\",\"oS\":\"\",\"cPu\":\"\",\"pspv\":0,\"pslv\":0,\"pssSr\":0,\"pswN\":0,\"psdS\":0,\"pscdT\":0,\"RP\":0,\"TPrice\":0,\"ML\":\"\",\"isReCaptchaOn\":false,\"reCaptchaSiteKey\":\"\",\"reCaptchaSecretKey\":\"\",\"extRefer\":\"\",\"dM2\":0,\"tM2\":0,\"sM2\":0,\"RA\":0,\"ToBlock\":-1}; __adblocker=true; _fs_cd_cp_pRdRgnTnF68pCV2F=AbFQG2O8u4sMHxbdt7jZB2MXsCYvy_jyCuquyEuLT76Amiptd9lNuJhvCm1FZTiYH-s_RHYKc6MworBIaX24qCBHEim5Z2G80jKgH_vZWn4hCstH-MwKFkACbbsgHEB_hcUYOolcBBh37xnO6i3mbfA=\n\
\n\
normalize_url: yes\n\
body: //div[contains(@data-component,'article-body')] | //main//article | //div[@id='site-content']\n\
strip: //div[contains(@class,'barrier')]\n\
strip: //div[contains(@data-component,'offer-card')]\n\
strip: //aside\n\
strip: //footer\n\
strip: //nav\n\
strip: //div[contains(@class,'ad')]\n\
prune: yes\n\
tidy: yes" > /var/www/html/site_config/custom/ft.com.txt

# 7. Duplicate the config to the 'standard' folder to ensure it overrides defaults
RUN cp /var/www/html/site_config/custom/ft.com.txt /var/www/html/site_config/standard/ft.com.txt

# 8. Permissions
RUN chown -R www-data:www-data /var/www/html
