FROM php:8.2-apache

# 1. Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    pkg-config libonig-dev libxml2-dev libcurl4-openssl-dev libtidy-dev git ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# 2. Enable Apache modules
RUN a2enmod rewrite

# 3. Install PHP extensions
RUN docker-php-ext-install -j$(nproc) mbstring curl dom tidy xml

# 4. Configure Apache ports
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
 && sed -i 's/:80/:8080/g' /etc/apache2/sites-available/000-default.conf

# 5. Allow .htaccess overrides
RUN sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html#g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf

# 6. Set working directory
WORKDIR /var/www/html

# 7. Copy the application code
COPY . /var/www/html

# 8. Create the custom config directory
RUN mkdir -p /var/www/html/site_config/custom

# 9. Write the ft.com config file with YOUR SPECIFIC COOKIE
# I have escaped the internal quotes in your cookie to prevent build errors.
RUN echo "http_header(User-Agent): Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36\n\
http_header(Accept-Language): en-US,en;q=0.9\n\
http_header(Referer): https://www.ft.com/\n\
http_header(Cookie): FTClientSessionId=958de9b0-3184-46ad-a174-9178c5329dd5; spoor-id=958de9b0-3184-46ad-a174-9178c5329dd5; _cb=CNhBxpBwqrkHDGsXBJ; FTCookieConsentGDPR=true; permutive-id=674830e9-d55b-4b24-96c1-cd9f00d959a9; _csrf=taOpgddMUYi3xJJ9jeL14WIP; _clck=ghh6j9%5E2%5Eg2e%5E0%5E2194; consentDate=2026-01-03T18:50:24.287Z; usnatUUID=01ae2de2-6b23-4e09-ad40-70d227bcbcec; _gcl_au=1.1.605766686.1767466222.465453575.1767466227.1767466241; _chartbeat2=.1760634544678.1767466247824.0000000000000001.DrLbjNC1iHp81jZ60Cx78CvD74kuB.1; _rdt_uuid=1767466221903.d8e97482-3d4d-44df-8cff-662d7851222f; _uetvid=d47ad560aab211f0b4a60bd43d926bdf; zit.data.toexclude=0; additionalInArticleSignupLanded=d3465ce9-ee23-44e4-82f5-9c9c6c990c57; _sxh=1900,1901,1902,; OriginalReferer=None; _fs_cd_cp_pRdRgnTnF68pCV2F=AXt6UvqNNZd-5r6g-nwHV9fVpx9XRVcEc0XJycwXceiledvug_dn80Ec6IiI8r1FK7L8d3HJFuOhvIe3FH8Q8PXs2_NuiQYkFb-IZaTssbhnw4zac56VR7iXal_SspODGnRzYsWHNag_3mQy3vJ4g1_G; __cf_bm=MoY0Ol6ip0icgLvqPBH0_nNFvyhdsj5ynF9QO6V85W0-1768596652-1.0.1.1-n4Men2CZ1ayT7iIXzWttb859hWW3_xDilKGMB9HWy8G4ebvUr74f8kjV8tBzlA62zH8ywqjl5UlKpnIydU9ieyCyz1tDn2JPgN9P0YyH6N8; FTSession_s=04eIIS18r0s706cObJFMpe_F0wAAAZvIk4Npw8I.MEYCIQDvNAINU2xXB_2SBJpJvOjnlYvG-0Kzbwc1ROJQYJCC-wIhANDzqAdUHkPtWCA_Hahq0stPJfV5fQag6tyHbc0wkfzZ; consentUUID=d373bc1e-77b4-4a96-9dcf-915bb6a5e1f8_28_31_41_45; skipPasskeySetup=false; FTConsent=behaviouraladsOnsite%3Aon%2CcookiesOnsite%3Aon%2CcookiesUseraccept%3Aon%2CdemographicadsOnsite%3Aon%2CenhancementByemail%3Aon%2CenhancementByfax%3Aoff%2CenhancementByphonecall%3Aon%2CenhancementBypost%3Aon%2CenhancementBysms%3Aoff%2CmarketingByemail%3Aon%2CmarketingByfax%3Aoff%2CmarketingByphonecall%3Aon%2CmarketingBypost%3Aon%2CmarketingBysms%3Aoff%2CmembergetmemberByemail%3Aoff%2CpermutiveadsOnsite%3Aon%2CpersonalisedmarketingOnsite%3Aon%2CprogrammaticadsOnsite%3Aon%2CrecommendedcontentOnsite%3Aon; FtComEntryPoint=/; ft-access-decision-policy=GRANTED_ZEPHR_REG_3_30; _sxo={\\\"R\\\":0,\\\"tP\\\":0,\\\"tM\\\":0,\\\"sP\\\":0,\\\"sM\\\":0,\\\"dP\\\":0,\\\"dM\\\":0,\\\"dS\\\":0,\\\"tS\\\":0,\\\"cPs\\\":0,\\\"lPs\\\":[],\\\"sSr\\\":0,\\\"sWids\\\":[],\\\"wN\\\":0,\\\"cdT\\\":0,\\\"F\\\":3,\\\"RF\\\":3,\\\"w\\\":0,\\\"SFreq\\\":0,\\\"last_wid\\\":0,\\\"bid\\\":1036,\\\"accNo\\\":\\\"\\\",\\\"clientId\\\":\\\"\\\",\\\"isEmailAud\\\":0,\\\"isPanelAud\\\":0,\\\"hDW\\\":0,\\\"isRegAud\\\":0,\\\"isExAud\\\":0,\\\"isDropoff\\\":0,\\\"devT\\\":4,\\\"exPW\\\":0,\\\"Nba\\\":-1,\\\"userName\\\":\\\"\\\",\\\"dataLayer\\\":\\\"\\\",\\\"localSt\\\":\\\"\\\",\\\"emailId\\\":\\\"\\\",\\\"emailTag\\\":\\\"\\\",\\\"subTag\\\":\\\"\\\",\\\"lVd\\\":\\\"\\\",\\\"oS\\\":\\\"\\\",\\\"cPu\\\":\\\"\\\",\\\"pspv\\\":0,\\\"pslv\\\":0,\\\"pssSr\\\":0,\\\"pswN\\\":0,\\\"psdS\\\":0,\\\"pscdT\\\":0,\\\"RP\\\":0,\\\"TPrice\\\":0,\\\"ML\\\":\\\"\\\",\\\"isReCaptchaOn\\\":false,\\\"reCaptchaSiteKey\\\":\\\"\\\",\\\"reCaptchaSecretKey\\\":\\\"\\\",\\\"extRefer\\\":\\\"\\\",\\\"dM2\\\":0,\\\"tM2\\\":0,\\\"sM2\\\":0,\\\"RA\\\":0,\\\"ToBlock\\\":-1}\n\
\n\
normalize_url: yes\n\
body: //div[contains(@data-component,'article-body')] | //main//article\n\
strip: //div[contains(@class,'barrier')]\n\
strip: //div[contains(@data-component,'offer-card')]\n\
strip: //aside\n\
strip: //footer\n\
strip: //nav\n\
strip: //div[contains(@class,'ad')]\n\
prune: yes\n\
tidy: yes" > /var/www/html/site_config/custom/ft.com.txt

# 10. Permissions
RUN chown -R www-data:www-data /var/www/html
