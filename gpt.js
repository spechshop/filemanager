const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
const websocket = require('ws');
const express = require('express');
const fs = require('fs');
const app = express();
app.use(express.json());

puppeteer.use(StealthPlugin());
const path = require('path');




// Arquivo para armazenar os cookies
const cookiesFilePath = path.join(__dirname, 'cookies.json');

const script = `




window.sendToChat = false;
window.sleep = false;
window.speak = false;
window.sendText = false;
window.completeData = false;
window.statusText = false;


(async () => {
    sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    sendToChat = async (text) => {
        document.querySelector("#prompt-textarea").innerText = text;
        await sleep(1000);
        document.querySelector('button[data-testid="send-button"]').click();
        await sleep(1000);
        speak(document.querySelectorAll('button[data-testid="voice-play-turn-action-button"]').length);
    }

    sendText = async (text64) => {
        // decodificar o text em base64
        let text = text64;


        const idTemp = Math.random().toString(36).substring(7);
        statusText = 'wait';
        completeData = '';
        let retryCount = 0;
        const maxRetries = 120;

        document.querySelector("#prompt-textarea").innerText = text;
        await sleep(1000);
        document.querySelector('button[data-testid="send-button"]').click();

        while (statusText === 'wait' && retryCount < maxRetries) {
            await sleep(1000); // Aumentei o tempo de espera
            retryCount++;
        }

        if (statusText === 'wait') {
            // Se ainda estiver esperando após todas as tentativas, considere finalizado
            statusText = 'done';
        }

        // Aguarde um pouco mais para garantir que temos os dados completos
        await sleep(500);

        return completeData;
    }

    speak = async (countsBt1ns) => {
        for (let i = 0; i < 10; i++) {
            await sleep(1000);
            if (document.querySelectorAll('button[data-testid="voice-play-turn-action-button"]').length > countsBt1ns) break;
        }
        let bt1ns = document.querySelectorAll('button[data-testid="voice-play-turn-action-button"]');
        let lastElement = bt1ns[bt1ns.length - 1];
        if (bt1ns.length > 0) lastElement = bt1ns[bt1ns.length - 1];
        lastElement.click();
    }
})();

(function () {
    const originalFetch = window.fetch;
    window.fetch = async function (...args) {
        let body;
        if (args[0].includes('backend-api/f/conversation')) {
            body = JSON.parse(args[1].body);
            body.model = 'gpt-4o';
            args[1].body = JSON.stringify(body);
        }

        try {
            const response = await originalFetch.apply(this, args);
            const clonedResponse = response.clone();
            if (response.url.includes('backend-api/f/conversation')) {
                console.log('Intercepted request to backend-api/conversation');
                const reader = clonedResponse.body.getReader();
                const decoder = new TextDecoder("utf-8");
                let partialData = '';
                completeData = '';
                let pd = '';
                let founded = false;
                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    partialData += decoder.decode(value, { stream: true });
                    const events = partialData.split("\\n");

                    let currentString = decoder.decode(value, { stream: true });
                    if (currentString.includes('data: [DONE]')) {
                        founded = true;
                        window.statusText = 'done';
                        break;
                    }

                    let data = currentString.split('data: ');
                    console.log(data)
                 let decodeJson = [];
for (let i = 0; i < data.length; i++) {
    if (data[i]) {
        try {
            decodeJson = JSON.parse(data[i].trim());
        } catch (e) {
            // console.error('Erro ao decodificar JSON:', data[i]);
            continue;
        }

        if (decodeJson.v) {
            if (typeof decodeJson.v === 'string') {
                pd += decodeJson.v;
            } else if (Array.isArray(decodeJson.v)) {
                for (let item of decodeJson.v) {
                    if (typeof item === 'string') {
                        pd += item;
                    } else {
                        console.warn('⚠️ Item não-string em array decodeJson.v:', item);
                    }
                }
            } else if (typeof decodeJson.v === 'object') {
                for (let key in decodeJson.v) {
                    if (typeof decodeJson.v[key] === 'string') {
                        console.log('o valor é suspeito!'+decodeJson.v[key])
                      //  pd += decodeJson.v[key];
                    } else {
                        console.warn('⚠️ Valor não-string na chave', key, ':', decodeJson.v[key]);
                    }
                }
            } else {
                console.warn('⚠️ Valor de decodeJson.v não iterável:', decodeJson.v);
            }

            console.log('decoded:', decodeJson);
        }
    }
}

                }
                if (founded) {
                    console.log(pd)
                    window.completeData = JSON.stringify({
                        message: {
                            content: {
                                content_type: 'text',
                                parts: [
                                    pd
                                ]
                            }
                        }
                    });
                }
                window.statusText = 'done';
            }

            if (response.url.includes('/backend-api/synthesize')) {
                const reader = clonedResponse.body.getReader();
                let chunks = [];
                let done = false;
                while (!done) {
                    const { value, done: currentDone } = await reader.read();
                    if (value) {
                        chunks.push(value);
                    }
                    done = currentDone;
                }
                const blob = new Blob(chunks, { type: response.headers.get('Content-Type') });
                const downloadUrl = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = downloadUrl;
                a.download = 'downloaded_file.aac';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(downloadUrl);
            }

            return response;
        } catch (error) {
            console.error('Erro ao interceptar a requisição:', error);
            return originalFetch.apply(this, args);
        }
    };
})();












`;

const argumentsPuppeteer = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-infobars',
    // rodar com root
    '--disable-dev-shm-usage',
    '--disable-accelerated-2d-canvas',
    '--window-position=0,0',
    '--ignore-certificate-errors',
    '--start-maximized',
    //'--headless'
    '--user-agent=Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
];
(async () => {

    const browser = (await puppeteer.launch({
        headless: true,
        cacheEnabled: true,
        devtools: false,
        //executablePath: '/usr/bin/google-chrome-stable',
        ignoreHTTPSErrors: true,
        args: argumentsPuppeteer,
    }));
    let lastcc = fs.readFileSync(cookiesFilePath, 'utf8');
    let cc = JSON.parse(lastcc);
    (await browser.setCookie(...cc));

    setInterval(async () => {
        let cc = (await browser.cookies());
        fs.writeFileSync(cookiesFilePath, JSON.stringify(cc, null, 2));
    }, 1000);



    const page = (await browser.pages())[0];
    // evento para ativar o script toda vez que a pagina carregar
    page.on('load', async () => {
        
        console.log('Página carregada, injetando script...', 'Titulo: ',(await ((await browser.pages())[0]) .title())    );
        await page.evaluate(script);
    });





    // Navegar para o ChatGPT
    await page.goto('https://chatgpt.com/c/68489f43-55b0-8010-8c57-decb05d21c2e', {waitUntil: 'networkidle2'});

    // Aguardar tempo para login ou carregamento
    await sleep(3000);

    // Salvar cookies após a navegação
    await saveCookies(page);

    await page.evaluate(script);

    let promptInit = 'sendText(`';
    promptInit += 'Olá, a partir de agora você só irá responder com código sem explicações ou palavras de diálogo.'
    promptInit += ' Se não puder responder com código, responda com "não posso ajudar".';
    promptInit += ' Valendo a partir de agora.`)';
    // await page.evaluate(promptInit);
    //await page.evaluate(`sendText('a partir de agora você só irá responde com código sem explicações ou palavras de dialogo, se não puder responder com código responda com "não posso ajudar" valendo a partir de agora')`);

    app.post('/api', async (req, res) => {
        try {
            // Log para debug
            console.log('Requisição recebida:', req.body);
            console.log((await page.title()));

            // Captura o texto do body
            const {text} = req.body;

            // Certifique-se de que o texto não está vazio
            if (!text) {
                return res.status(400).json({error: 'Text field is required'});
            }

            // Usa o page.evaluate de forma segura
            //const text64 = Buffer.from(text).toString('base64');
            let text64 = text;
            const response = await page.evaluate(async (text64) => {
                return await sendText(text64); // Chama a função sendText com o texto codificado em base64


            }, text64);
            console.log('Resposta bruta:', response);

            // Tente fazer o parse do JSON ou retorne a resposta como texto se falhar
            let responseJson;
            try {
                responseJson = JSON.parse(response);
            } catch (error) {
                console.log('Falha ao fazer parse JSON, retornando como texto:', error, response);
                return res.status(200).json({
                    content: {
                        content_type: 'text',
                        parts: [response]
                    }
                });
            }

            // Verifica a estrutura do JSON e extrai o conteúdo da mensagem
            const {message} = responseJson;
            if (!message) {
                console.log('Resposta sem mensagem:', responseJson);
                return res.status(200).json({
                    content: {
                        content_type: 'text',
                        parts: [JSON.stringify(responseJson)]
                    }
                });
            }

            if (!message.content) {
                console.log('Mensagem sem conteúdo:', message);
                return res.status(200).json({
                    content: {
                        content_type: 'text',
                        parts: [JSON.stringify(message)]
                    }
                });
            }

            // Envia a resposta final
            res.status(200).json(message.content);
        } catch (error) {
            console.error('Erro na rota API:', error);
            res.status(500).json({
                error: 'Erro interno do servidor',
                message: error.message,
                stack: process.env.NODE_ENV === 'development' ? error.stack : undefined
            });
        }
    });

  const port = process.env.PORT || 3090;

app.listen(port, async () => {
    console.log(`🚀 Servidor iniciado na porta ${port}`);

    try {
        console.log('🌐 Acessando ChatGPT...');
        await page.goto('https://chatgpt.com/c/68489f43-55b0-8010-8c57-decb05d21c2e', { waitUntil: 'networkidle2' });

        console.log('✅ Página carregada:', await page.title());

        const ok = await page.evaluate(() => typeof window.sendText === 'function');
        if (!ok) {
            console.log('⚠️ sendText não estava disponível. Reinjetando script...');
            await page.evaluate(script);
        }

        console.log('🧠 Script injetado e pronto pra uso!');
    } catch (err) {
        console.error('❌ Erro ao acessar página:', err.message);
    }
});


    // Salvar cookies periodicamente (a cada 5 minutos)
    setInterval(async () => {
        console.log('Salvando cookies periodicamente...');
        await saveCookies(page);
    }, 5 * 60 * 1000);

    // Salvar cookies ao encerrar o aplicativo
    process.on('SIGINT', async () => {
        console.log('Salvando cookies e encerrando...');
        await saveCookies(page);
        await browser.close();
        process.exit(0);
    });




})();

async function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Função para salvar cookies em arquivo
async function saveCookies(page) {
    const cookies = await page.cookies();
    fs.writeFileSync(cookiesFilePath, JSON.stringify(cookies, null, 2));
    console.log('Cookies salvos em:', cookiesFilePath);
}

// Função para carregar cookies de um arquivo
async function loadCookies(page) {
    try {
        if (fs.existsSync(cookiesFilePath)) {
            const cookiesString = fs.readFileSync(cookiesFilePath, 'utf8');
            const cookies = JSON.parse(cookiesString);
            await page.setCookie(...cookies);
            console.log('Cookies carregados com sucesso');
            return true;
        }
    } catch (error) {
        console.error('Erro ao carregar cookies:', error);
    }
    return false;
}
