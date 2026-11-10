<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Robots\Registry;

use Quince\Robots\Storage\Schema;

/**
 * Zestaw startowy rejestru.
 *
 * Migawka listy `ai-robots-txt/ai.robots.txt` (MIT) uzupełniona o pola, których
 * tamta nie ma: kategorię, sposób weryfikacji i naszą rekomendację. Kategoria
 * i rekomendacja to **nasz osąd**, nie fakt o bocie — tamta lista mówi, co bot
 * robi, ale nie mówi, czy go wpuścić.
 *
 * Wpisów jest tu 162, bo cała lista jedzie w module. Sprzedawca dostaje więc
 * zaszeregowany komplet od pierwszego uruchomienia, bez czekania na pobranie
 * czegokolwiek, a odświeżanie spisu dokłada tylko to, co doszło później.
 *
 * `policy` celowo nie ma tu żadnego wpisu. Zestaw niesie **rekomendację**
 * (`default_policy`), a decyzja należy do sprzedawcy i zapisuje się osobno.
 *
 * Kategoria `search` jest w rejestrze wyłącznie po to, żeby ekran mógł pokazać,
 * że o tych robotach też wiemy. Blokiem w `robots.txt` nimi nie sterujemy —
 * należą do panelu SEO PrestaShopa.
 *
 * **Czego tu nie ma i dlatego.** Z listy źródłowej pomijamy nazwy, które jako
 * fragment dopasowania złapałyby ruch, o który nikomu nie chodziło: `Spider`
 * (siedzi w nagłówku setek zwykłych indeksatorów), `Code` i `OpenAI` (ta ostatnia
 * jest w nagłówku GPTBota). Wszystkie trzy mają w źródle pusty wpis o producencie,
 * czyli są tam zaślepkami. Pomijamy też nazwy krótsze niż pięć znaków i te
 * z numerem wersji, gdy lista ma je również bez numeru.
 */
final class DefaultBots
{
    /**
     * @return array<int,array{key:string,token:string,label:string,vendor:string,category:string,description:string,docs_url:string,verify_host:string,respects:string,default_policy:string}>
     */
    public static function all(): array
    {
        return [
            // --- wyszukiwarki: pokazujemy, nie sterujemy ---------------------
            self::wpis('googlebot', 'Googlebot', 'Google', 'search', 'Indexes pages for Google Search.', 'https://developers.google.com/search/docs/crawling-indexing/googlebot', 'googlebot.com', 'yes'),
            self::wpis('bingbot', 'bingbot', 'Microsoft', 'search', 'Indexes pages for Bing.', 'https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0', 'search.msn.com', 'yes'),
            self::wpis('duckduckbot', 'DuckDuckBot', 'DuckDuckGo', 'search', 'Indexes pages for DuckDuckGo.', 'https://duckduckgo.com/duckduckbot', '', 'yes'),
            self::wpis('applebot', 'Applebot', 'Apple', 'search', 'Indexes pages for Siri and Spotlight suggestions.', 'https://support.apple.com/en-us/119829', 'applebot.apple.com', 'yes'),

            // --- silniki odpowiedzi: odsyłają czytelnika do sklepu -----------
            self::wpis('oai-searchbot', 'OAI-SearchBot', 'OpenAI', 'answer', 'Builds the search index behind ChatGPT answers, which link back to the source.', 'https://platform.openai.com/docs/bots', '', 'yes'),
            self::wpis('chatgpt-user', 'ChatGPT-User', 'OpenAI', 'answer', 'Fetches a page because a person asked ChatGPT about it right now.', 'https://platform.openai.com/docs/bots', '', 'yes'),
            self::wpis('perplexitybot', 'PerplexityBot', 'Perplexity', 'answer', 'Indexes pages for Perplexity answers, with a link to the source.', 'https://docs.perplexity.ai/guides/bots', '', 'yes'),
            self::wpis('claude-user', 'Claude-User', 'Anthropic', 'answer', 'Fetches a page because a person asked Claude about it right now.', 'https://support.anthropic.com/en/articles/8896518', '', 'yes'),
            self::wpis('claude-searchbot', 'Claude-SearchBot', 'Anthropic', 'answer', 'Builds the search index behind Claude answers.', 'https://support.anthropic.com/en/articles/8896518', '', 'yes'),
            self::wpis('amazonbot', 'Amazonbot', 'Amazon', 'answer', 'Collects pages that Alexa quotes when answering questions.', 'https://developer.amazon.com/amazonbot', '', 'yes'),
            self::wpis('addsearchbot', 'AddSearchBot', '', 'answer', 'AddSearchBot is a web crawler that indexes website content for AddSearch\'s AI-powered site search solution, collecting data to provide fast and accurate search results.', '', '', 'unclear'),
            self::wpis('ai2bot-deepresearcheval', 'AI2Bot-DeepResearchEval', 'Ai2', 'answer', 'Ai2Bot-DeepResearchEval is operated by Ai2, a non-profit AI research institute.', '', '', 'unclear'),
            self::wpis('aiwebindex', 'AIWebIndex', 'Lyrenth', 'answer', 'Builds an index of public pages and serves them to AI agents as extracted, readable text with attribution and a link back to the source.', 'https://lyrenth.com/crawler-policy', '', 'yes'),
            self::wpis('amazon-kendra', 'amazon-kendra', 'Amazon', 'answer', 'Amazon Kendra is a highly accurate intelligent search service that enables your users to search unstructured data using natural language.', '', '', 'yes'),
            self::wpis('amazon-qbusiness', 'amazon-QBusiness', '', 'answer', 'amazon-QBusiness is an Amazon Q Business web crawler that fetches and indexes web content for Amazon Q Business applications.', '', '', 'unclear'),
            self::wpis('amazonbuyforme', 'AmazonBuyForMe', 'Amazon', 'answer', 'AmazonBuyForMe is an Amazon bot that crawls websites as part of the Amazon Buy For Me service.', 'https://amazon.com', '', 'unclear'),
            self::wpis('amzn-searchbot', 'Amzn-SearchBot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('amzn-user', 'Amzn-User', 'Amazon', 'answer', 'Amzn-User is an AI assistant operated by Amazon, used for fetching web content to answer user queries through Alexa and other Amazon AI services.', '', '', 'unclear'),
            self::wpis('anomura', 'Anomura', 'Direqt', 'answer', 'Anomura is Direqt\'s search crawler, it discovers and indexes pages their customers websites.', 'https://direqt.ai', '', 'yes'),
            self::wpis('aranet-searchbot', 'Aranet-SearchBot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('atlassian-bot', 'atlassian-bot', 'Atlassian', 'answer', 'atlassian-bot is a web crawler used to index website content for its AI search, assistants and agents available in its Rovo GenAI product.', 'https://support.atlassian.com/organization-administration/docs/connect-custom-website-to-rovo/#Editing-your-robots.txt', '', 'yes'),
            self::wpis('azureai-searchbot', 'AzureAI-SearchBot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('bigsur-ai', 'bigsur.ai', '', 'answer', 'bigsur.ai is a web crawler operated by Big Sur AI that fetches website content to enable AI-powered web agents, sales assistants, and content marketing solutions for busi…', '', '', 'unclear'),
            self::wpis('buddybot', 'BuddyBot', 'BuddyBotLearning', 'answer', 'BuddyBot is a voice-controlled AI learning companion targeted at childhooded STEM education.', 'https://www.buddybotlearning.com', '', 'unclear'),
            self::wpis('channel3bot', 'Channel3Bot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('chatgpt-agent', 'ChatGPT Agent', 'OpenAI', 'answer', 'ChatGPT Agent is an AI agent created by OpenAI that can use a web browser.', 'https://openai.com', '', 'yes'),
            self::wpis('claude-code', 'Claude-Code', '', 'answer', 'Claude Code is an AI coding agent by Anthropic that can build, debug, and ship code directly from the terminal, handling tasks like codebase onboarding, multi-file edits,…', '', '', 'unclear'),
            self::wpis('claude-web', 'Claude-Web', 'Anthropic', 'answer', 'Claude-Web is an AI-related agent operated by Anthropic.', '', '', 'unclear'),
            self::wpis('cloudflare-autorag', 'Cloudflare-AutoRAG', 'Cloudflare', 'answer', 'AutoRAG is an all-in-one AI search solution.', 'https://developers.cloudflare.com/autorag', '', 'yes'),
            self::wpis('cohere-ai', 'cohere-ai', 'Cohere', 'answer', 'Retrieves data based on user prompts.', 'https://cohere.com', '', 'unclear'),
            self::wpis('crawl4ai', 'Crawl4AI', '', 'answer', '', '', '', 'unclear'),
            self::wpis('cursor', 'Cursor', '', 'answer', 'Cursor is an AI coding agent that helps write, edit, and understand code.', '', '', 'unclear'),
            self::wpis('devin', 'Devin', 'Devin AI', 'answer', 'Devin is a software engineering AI assistant that can browse websites and perform web-based tasks, functioning as a collaborative AI teammate for engineering teams.', '', '', 'yes'),
            self::wpis('duckassistbot', 'DuckAssistBot', '', 'answer', 'DuckAssistBot is a web crawler that scans websites to collect content for DuckDuckGo\'s AI-assisted answers feature, which generates brief responses to search queries usin…', 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot/', '', 'yes'),
            self::wpis('exasearchbot', 'ExaSearchBot', 'Exa', 'answer', 'ExaSearchBot is a web crawler operated by Exa that discovers and indexes public web pages so their content can be found, retrieved, and cited through Exa.', 'https://exa.ai', '', 'unclear'),
            self::wpis('geisthaus-pagefetcher', 'GeistHaus-PageFetcher', 'GeistHaus', 'answer', 'GeistHaus-PageFetcher is a web crawler operated by GeistHaus, a company developing AI systems for therapy and psychological assessment.', '', '', 'unclear'),
            self::wpis('gemini-deep-research', 'Gemini-Deep-Research', '', 'answer', 'Gemini-Deep-Research is the agent responsible for collecting and scanning resources used in Google Gemini\'s Deep Research feature, which acts as a personal research assis…', '', '', 'unclear'),
            self::wpis('google-agent', 'Google-Agent', '', 'answer', 'Google-Agent is used by agents hosted on Google infrastructure to navigate the web and perform actions upon user request.', 'https://developers.google.com/search/docs/crawling-indexing/google-common-crawlers#google-agent', '', 'yes'),
            self::wpis('google-gemini-cli', 'Google-Gemini-CLI', '', 'answer', 'Gemini CLI is an AI coding agent by Google that can query and edit large codebases, generate apps from images or PDFs, and automate complex workflows directly from the te…', '', '', 'unclear'),
            self::wpis('google-notebooklm', 'Google-NotebookLM', '', 'answer', 'Google-NotebookLM is an AI-powered research and note-taking assistant that helps users synthesize information from uploaded sources like documents, transcripts, or web co…', '', '', 'unclear'),
            self::wpis('googleagent-mariner', 'GoogleAgent-Mariner', 'Google', 'answer', 'GoogleAgent-Mariner is an AI agent created by Google that can use a web browser.', '', '', 'unclear'),
            self::wpis('googleagent-urlcontext', 'GoogleAgent-URLContext', '', 'answer', 'GoogleAgent-URLContext is a web fetcher operated by Google that retrieves web content on behalf of Gemini API users.', '', '', 'unclear'),
            self::wpis('iaskbot', 'iAskBot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('iaskspider', 'iaskspider', '', 'answer', '', '', '', 'unclear'),
            self::wpis('kagi-fetcher', 'kagi-fetcher', '', 'answer', 'kagi-fetcher is an AI Assistant operated by Kagi that fetches web content to answer user queries through Kagi AI, their suite of AI-powered tools including Assistant, Res…', '', '', 'unclear'),
            self::wpis('kimi-user', 'Kimi-User', '', 'answer', 'Kimi-User is a web crawler operated by Moonshot AI that fetches web content on behalf of users interacting with Kimi.', '', '', 'unclear'),
            self::wpis('klaviyoaibot', 'KlaviyoAIBot', 'Klaviyo', 'answer', 'KlaviyoAIBot is Klaviyo\'s web crawler that fetches publicly available pages from domains explicitly connected to user accounts to power the Kai Customer Agent feature.', 'https://help.klaviyo.com/hc/en-us/articles/40496146232219', '', 'yes'),
            self::wpis('kunatocrawler', 'KunatoCrawler', '', 'answer', '', '', '', 'unclear'),
            self::wpis('linerbot', 'LinerBot', '', 'answer', 'LinerBot is the web crawler used by Liner AI assistant to gather information from academic sources and websites to provide accurate answers with line-by-line source citat…', '', '', 'unclear'),
            self::wpis('linkupbot', 'LinkupBot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('manus-user', 'Manus-User', 'Butterfly Effect', 'answer', 'Manus-User is a browser-enabled AI agent operated by Butterfly Effect, a company based in China.', '', '', 'unclear'),
            self::wpis('meta-externalfetcher', 'meta-externalfetcher', '', 'answer', 'meta-externalfetcher is used by Meta to perform user-initiated fetches of individual links from AI assistant product functions.', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/', '', 'no'),
            self::wpis('meta-webindexer', 'meta-webindexer', 'Meta', 'answer', 'As per their documentation, "The Meta-WebIndexer crawler navigates the web to improve Meta AI search result quality for users.', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/', '', 'unclear'),
            self::wpis('mistralai-user', 'MistralAI-User', 'Mistral', 'answer', 'MistralAI-User is Mistral\'s AI assistant bot that performs web browsing and data gathering tasks for users in Le Chat, including opening web pages and retrieving informat…', '', '', 'unclear'),
            self::wpis('notebooklm', 'NotebookLM', '', 'answer', 'NotebookLM is an AI-powered research and note-taking assistant that helps users synthesize information from their own uploaded sources, such as documents, transcripts, or web content.', '', '', 'unclear'),
            self::wpis('novaact', 'NovaAct', '', 'answer', 'Nova Act is an AI agent created by Amazon that can use a web browser.', '', '', 'unclear'),
            self::wpis('opencode', 'opencode', '', 'answer', 'OpenCode is an open-source AI coding agent that helps developers write code from the terminal, IDE, or desktop, supporting multiple LLM providers and local models.', '', '', 'unclear'),
            self::wpis('operator', 'Operator', '', 'answer', 'Operator is an AI agent created by OpenAI that can use a web browser.', '', '', 'unclear'),
            self::wpis('perplexity-user', 'Perplexity-User', 'Perplexity', 'answer', 'Perplexity-User supports user actions within Perplexity.', 'https://docs.perplexity.ai/guides/bots', '', 'no'),
            self::wpis('petalbot', 'PetalBot', 'Huawei', 'answer', 'Operated by Huawei to provide search and AI assistant services.', 'https://huawei.com/', '', 'yes'),
            self::wpis('phindbot', 'PhindBot', 'phind', 'answer', 'Phind is an AI-powered answer engine designed for developers, offering technical answers and code examples.', 'https://www.phind.com/', '', 'unclear'),
            self::wpis('poggio-citations', 'Poggio-Citations', 'Poggio', 'answer', 'Poggio-Citations is a web crawler operated by Poggio, a company that provides AI sales enablement tools for creating tailored narratives, business cases, and account plan…', '', '', 'unclear'),
            self::wpis('poseidon-research-crawler', 'Poseidon Research Crawler', 'Poseidon Research', 'answer', 'Lab focused on scaling the interpretability research necessary to make better AI systems possible.', 'https://www.poseidonresearch.com', '', 'unclear'),
            self::wpis('qualifiedbot', 'QualifiedBot', 'Qualified', 'answer', 'QualifiedBot is Qualified\'s web crawler that analyzes customer websites to provide contextual information for their AI-powered chatbots and conversational marketing platf…', 'https://www.qualified.com', '', 'unclear'),
            self::wpis('reflectionbot', 'Reflectionbot', 'Reflection', 'answer', 'An undocumented crawler whose user agent links to Reflection, a company that builds AI models.', 'https://reflection.ai/', '', 'unclear'),
            self::wpis('shap-user', 'Shap-User', '', 'answer', 'Shap-User accesses web content on behalf of users of Parallel Web Systems products.', '', '', 'unclear'),
            self::wpis('tongyibot', 'TongyiBot', '', 'answer', 'TongyiBot is a web crawler operated by Alibaba that fetches web content for the Tongyi Qianwen assistant and related Qwen-generated answers.', '', '', 'unclear'),
            self::wpis('twinagent', 'TwinAgent', 'Twin', 'answer', 'TwinAgent is operated by Twin, a platform that creates automated workers to perform tasks by integrating with APIs and controlling web applications through browser automa…', '', '', 'unclear'),
            self::wpis('useai', 'UseAI', '', 'answer', 'UseAI is a web crawler associated with Use AI, a platform that provides an AI workspace where users can chat with AI models, research the web, and perform various tasks.', '', '', 'unclear'),
            self::wpis('wrtnbot', 'WRTNBot', '', 'answer', '', '', '', 'unclear'),
            self::wpis('yiyanbot', 'YiyanBot', '', 'answer', 'YiyanBot is a web crawler operated by Baidu that fetches web content for the yiyan.baidu.com assistant and related ERNIE-generated answers.', '', '', 'unclear'),
            self::wpis('zanistabot', 'ZanistaBot', '', 'answer', '', '', '', 'unclear'),

            // --- trenowanie modeli: biorą treść, nic nie odsyłają ------------
            self::wpis('gptbot', 'GPTBot', 'OpenAI', 'training', 'Collects pages to train OpenAI models.', 'https://platform.openai.com/docs/bots', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('claudebot', 'ClaudeBot', 'Anthropic', 'training', 'Collects pages to train Anthropic models.', 'https://support.anthropic.com/en/articles/8896518', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('anthropic-ai', 'anthropic-ai', 'Anthropic', 'training', 'Older name of the Anthropic collector; still seen in logs.', 'https://support.anthropic.com/en/articles/8896518', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('google-extended', 'Google-Extended', 'Google', 'training', 'Decides whether pages feed Gemini training. Blocking it does not affect Search.', 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('ccbot', 'CCBot', 'Common Crawl', 'training', 'Builds the public Common Crawl dataset, which many model builders use.', 'https://commoncrawl.org/ccbot', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('bytespider', 'Bytespider', 'ByteDance', 'training', 'Collects pages to train ByteDance models.', '', '', 'no', Schema::POLICY_BLOCK),
            self::wpis('meta-externalagent', 'meta-externalagent', 'Meta', 'training', 'Collects pages to train Meta models.', 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('applebot-extended', 'Applebot-Extended', 'Apple', 'training', 'Decides whether pages feed Apple model training. Blocking it does not affect Siri.', 'https://support.apple.com/en-us/119829', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('agenttimes', 'AgentTimes', 'The Agent Times', 'training', 'Scrapes data for AI news aggregation and republishing.', 'https://theagenttimes.com/about', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('ai2bot', 'AI2Bot', 'Ai2', 'training', 'Explores \'certain domains\' to find web content.', 'https://allenai.org/crawler', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('ai2bot-dolma', 'Ai2Bot-Dolma', 'Ai2', 'training', 'Explores \'certain domains\' to find web content.', 'https://allenai.org/crawler', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('aihitbot', 'aiHitBot', 'aiHit', 'training', 'Scrapes data for AI systems.', 'https://www.aihitdata.com/about', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('andibot', 'Andibot', 'Andi', 'training', 'Scrapes website and provides AI summary.', 'https://andisearch.com/', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('apifybot', 'ApifyBot', '', 'training', 'ApifyBot is a web scraping and data extraction crawler by Apify that collects website content for use in AI, LLMs, RAG, and automation workflows.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('apifywebsitecontentcrawler', 'ApifyWebsiteContentCrawler', '', 'training', 'ApifyWebsiteContentCrawler is a web crawler by Apify that extracts and downloads full website content for use in AI, data analysis, and automation workflows.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('awario', 'Awario', 'Awario', 'training', 'Awario is an AI data scraper operated by Awario.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('bedrockbot', 'bedrockbot', 'Amazon', 'training', 'Connects to and crawls URLs that have been selected for use in a user\'s AWS bedrock application.', 'https://docs.aws.amazon.com/bedrock/latest/userguide/webcrawl-data-source-connector.html#configuration-webcrawl-connector', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('bravebot', 'Bravebot', '', 'training', 'Bravebot is a web crawler by Brave that indexes pages for Brave Search, providing search data and AI-optimized context to power chatbots, agents, and RAG pipelines.', '', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('brightbot', 'Brightbot', '', 'training', 'Brightbot is a web data collection crawler by Bright Data that extracts and structures public website content at scale, providing AI-ready data for model training, RAG pi…', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('chatglm-spider', 'ChatGLM-Spider', '', 'training', '', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('cloudvertexbot', 'CloudVertexBot', '', 'training', 'CloudVertexBot is a Google-operated crawler available to site owners to request targeted crawls of their own sites for AI training purposes on the Vertex AI platform.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('cohere-training-data-crawler', 'cohere-training-data-crawler', '', 'training', 'cohere-training-data-crawler is a web crawler operated by Cohere to download training data for its LLMs (Large Language Models) that power its enterprise AI products.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('cotoyogi', 'Cotoyogi', 'ROIS', 'training', 'Scrapes data for AI training in Japanese language.', 'https://ds.rois.ac.jp/en_center8/en_crawler/', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('cragcrawler', 'CragCrawler', 'CragSoftware', 'training', 'CragCrawler is a web scraping bot operated by CragSoftware, a Brazil-based software company specializing in data engineering and AI web scraping services.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('crawlspace', 'Crawlspace', 'Crawlspace', 'training', 'Crawlspace is a web crawler platform that fetches and extracts website content for AI agents, RAG applications, and structured data workflows.', 'https://news.ycombinator.com/item?id=42756654', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('datenbank-crawler', 'Datenbank Crawler', 'Datenbank', 'training', 'Datenbank Crawler is an AI data scraper operated by Datenbank.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('deepseekbot', 'DeepSeekBot', 'DeepSeek', 'training', 'DeepSeekBot is a web crawler used by DeepSeek to train its language models and improve its AI products.', '', '', 'no', Schema::POLICY_BLOCK),
            self::wpis('diffbot', 'Diffbot', 'Diffbot', 'training', 'Diffbot is a web crawler that extracts and structures website content using AI-powered visual understanding, providing knowledge graph data for applications like market i…', 'https://www.diffbot.com/', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('echobot-bot', 'Echobot Bot', 'Echobox', 'training', 'Echobot Bot is an AI data scraper operated by Echobox.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('echoboxbot', 'EchoboxBot', 'Echobox', 'training', 'Supports company\'s AI-powered social and email management products.', 'https://echobox.com', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('exabot', 'ExaBot', '', 'training', 'ExaBot is a web crawler that indexes web content to power Exa\'s AI search engine and semantic search APIs for AI applications.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('facebookbot', 'FacebookBot', 'Meta/Facebook', 'training', 'Officially used for training Meta "speech recognition technology," unknown if used to train Meta AI specifically.', 'https://developers.facebook.com/docs/sharing/bot/', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('factset-spyderbot', 'Factset_spyderbot', 'Factset', 'training', 'Scrapes data for AI training.', 'https://www.factset.com/ai', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('firecrawlagent', 'FirecrawlAgent', '', 'training', 'FirecrawlAgent is a web crawler operated by Firecrawl that extracts web content and converts it into structured data for use in LLM and AI applications.', '', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('friendlycrawler', 'FriendlyCrawler', 'Unknown', 'training', 'We are using the data from the crawler to build datasets for machine learning experiments.', 'https://imho.alex-kunz.com/2024/01/25/an-update-on-friendly-crawler', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('google-cloudvertexbot', 'Google-CloudVertexBot', 'Google', 'training', 'Google-CloudVertexBot crawls sites on the site owners\' request when building Vertex AI Agents.', 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('googleother', 'GoogleOther', 'Google', 'training', '"Used by various product teams for fetching publicly accessible content from sites.', 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('googleother-image', 'GoogleOther-Image', 'Google', 'training', '"Used by various product teams for fetching publicly accessible content from sites.', 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('googleother-video', 'GoogleOther-Video', 'Google', 'training', '"Used by various product teams for fetching publicly accessible content from sites.', 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('henkbot', 'HenkBot', '', 'training', 'Henkbot crawls the web on behalf of Valyu, an AI search infrastructure provider that indexes content for use in AI-powered retrieval pipelines.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('icc-crawler', 'ICC-Crawler', 'NICT', 'training', 'Use the collected data for artificial intelligence technologies; provide data to third parties, including commercial companies; those companies can use the data for their own business.', 'https://nict.go.jp', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('imagesiftbot', 'ImagesiftBot', 'ImageSift', 'training', 'Once images and text are downloaded from a webpage, ImageSift analyzes this data from the page and stores the information in an index.', 'https://imagesift.com/about', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('imagespider', 'imageSpider', '', 'training', '', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('img2dataset', 'img2dataset', 'img2dataset', 'training', 'Downloads large sets of images into datasets for LLM training or other purposes.', 'https://github.com/rom1504/img2dataset', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('isscyberriskcrawler', 'ISSCyberRiskCrawler', 'ISS-Corporate', 'training', 'Used to train machine learning based models to quantify cyber risk.', 'https://iss-cyber.com', '', 'no', Schema::POLICY_BLOCK),
            self::wpis('kangaroo-bot', 'Kangaroo Bot', '', 'training', 'Kangaroo Bot is used by the company Kangaroo LLM to download data to train AI models tailored to Australian language and culture.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('laion-huggingface-processor', 'laion-huggingface-processor', '', 'training', '', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('laiondownloader', 'LAIONDownloader', '', 'training', 'LAIONDownloader is a bot by LAION, a non-profit organization that provides datasets, tools and models to liberate machine learning research.', 'https://laion.ai/faq/', '', 'no', Schema::POLICY_BLOCK),
            self::wpis('lightpanda', 'Lightpanda', '', 'training', 'Lightpanda is a custom-built headless browser designed for AI and automation.', 'https://github.com/lightpanda-io/browser/blob/b04c99a9111564ebe06317f644680eda5e3ee83e/src/help.zon#L385', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('linguee-bot', 'Linguee Bot', 'Linguee', 'training', 'Linguee Bot is a web crawler used by Linguee to gather training data for its AI powered translation service.', 'https://www.linguee.com', '', 'no', Schema::POLICY_BLOCK),
            self::wpis('mozilla-tabstack', 'Mozilla-Tabstack', '', 'training', 'Mozilla-Tabstack is an AI agent operated by Mozilla that performs programmatic, AI-driven interactions with web content through Tabstack.', '', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('mycentralaiscraperbot', 'MyCentralAIScraperBot', '', 'training', 'Operator and data use is unclear at this time.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('nagetbot', 'NagetBot', '', 'training', '\'Naget revolutionizes content discovery through an AI-powered ecosystem that transforms how we generate, organize, share, and discover valuable content.\' (https://naget.com/) User-agent string links…', 'https://naget.com/', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('netestate-imprint-crawler', 'netEstate Imprint Crawler', 'netEstate', 'training', 'netEstate Imprint Crawler is an AI data scraper operated by netEstate.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('newsai', 'newsai', '', 'training', 'User-agent string doen\'t contain an URL and there multiple sites using the newsai brand.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('omgili', 'omgili', 'Webz.io', 'training', 'Crawls sites for APIs used by Hootsuite, Sprinklr, NetBase, and other companies.', 'https://webz.io/blog/web-data/what-is-the-omgili-bot-and-why-is-it-crawling-your-website/', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('omgilibot', 'omgilibot', 'Webz.io', 'training', 'Legacy user agent initially used for Omgili search engine.', 'https://web.archive.org/web/20170704003301/http://omgili.com/Crawler.html', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('pangubot', 'PanguBot', '', 'training', 'PanguBot is a web crawler operated by the Chinese company Huawei.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('panscient', 'Panscient', 'Panscient', 'training', 'Compiles data on businesses and business professionals that is structured using AI and machine learning.', 'https://panscient.com/faq.htm', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('panscient-com', 'panscient.com', 'Panscient', 'training', 'Compiles data on businesses and business professionals that is structured using AI and machine learning.', 'https://panscient.com/faq.htm', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('querit-searchbot', 'Querit-SearchBot', '', 'training', 'Querit-SearchBot is a web crawler operated by Querit that indexes web content for their search API service, which is designed to provide real-time search results for larg…', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('queritbot', 'QueritBot', 'Querit', 'training', 'QueritBot is a web crawler operated by Querit, a company providing a search API for large language model integration.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('sbintuitionsbot', 'SBIntuitionsBot', 'SB Intuitions', 'training', 'AI development and information analysis', 'https://www.sbintuitions.co.jp/en/bot/', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('scrapy', 'Scrapy', 'Zyte', 'training', '"AI and machine learning applications often need large amounts of quality data, and web data extraction is a fast, efficient way to build structured data sets."', 'https://www.zyte.com', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('shapbot', 'ShapBot', 'Parallel', 'training', 'ShapBot is a web crawler by Parallel that collects and structures web content to power its search, extraction, and deep research APIs, providing AI agents with high-accur…', 'https://docs.parallel.ai/features/crawler', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('sidetrade-indexer-bot', 'Sidetrade indexer bot', 'Sidetrade', 'training', 'AI product training.', 'https://www.sidetrade.com', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('tavilybot', 'TavilyBot', '', 'training', 'TavilyBot is a web crawler by Tavily that indexes and extracts content from billions of pages, providing real-time search, extraction, and research data to ground AI agen…', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('terra-cotta', 'Terra Cotta', '', 'training', 'Terra Cotta is Ceramic\'s web crawler that indexes public content to power their web-scale search API for AI and LLMs.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('terracotta', 'TerraCotta', 'Ceramic AI', 'training', 'TerraCotta is Ceramic\'s web crawler that indexes public content to power their web-scale search API for AI and LLMs.', 'https://github.com/CeramicTeam/CeramicTerracotta', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('tiktokspider', 'TikTokSpider', 'ByteDance', 'training', 'Downloads data to train LLMS, as per Bytespider.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('timpibot', 'Timpibot', 'Timpi', 'training', 'Makes data available for training AI models.', 'https://timpi.io', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('velenpublicwebcrawler', 'VelenPublicWebCrawler', 'Velen Crawler', 'training', '"Our goal with this crawler is to build business datasets and machine learning models to better understand the web."', 'https://velen.io', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('wardbot', 'WARDBot', 'WEBSPARK', 'training', 'WARDBot is an AI data scraper operated by WEBSPARK.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('webzio-extended', 'Webzio-Extended', '', 'training', 'Webzio-Extended is a web crawler used by Webz.io to maintain a repository of web crawl data that it sells to other companies, including those using it to train AI models.', '', '', 'unclear', Schema::POLICY_BLOCK),
            self::wpis('yandexadditional', 'YandexAdditional', 'Yandex', 'training', 'Retrieves data used for YandexGPT quick answers features.', 'https://yandex.ru/support/webmaster/en/search-appearance/fast.html?lang=en', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('yandexadditionalbot', 'YandexAdditionalBot', 'Yandex', 'training', 'Retrieves data used for YandexGPT quick answers features.', 'https://yandex.ru/support/webmaster/en/search-appearance/fast.html?lang=en', '', 'yes', Schema::POLICY_BLOCK),
            self::wpis('youbot', 'YouBot', 'You', 'training', 'Retrieves data used for You.com web search engine and LLMs.', 'https://about.you.com/youbot/', '', 'yes', Schema::POLICY_BLOCK),

            // --- narzędzia SEO: sprzedawca zwykle sam z nich korzysta --------
            self::wpis('ahrefsbot', 'AhrefsBot', 'Ahrefs', 'seo', 'Maps links between sites for the Ahrefs SEO tool.', 'https://ahrefs.com/robot', '', 'yes'),
            self::wpis('semrushbot', 'SemrushBot', 'Semrush', 'seo', 'Maps links and keywords for the Semrush SEO tool.', 'https://www.semrush.com/bot/', '', 'yes'),
            self::wpis('mj12bot', 'MJ12bot', 'Majestic', 'seo', 'Maps links between sites for the Majestic index.', 'https://mj12bot.com/', '', 'yes'),
            self::wpis('dotbot', 'DotBot', 'Moz', 'seo', 'Maps links between sites for the Moz index.', 'https://moz.com/help/moz-procedures/crawlers/dotbot', '', 'yes'),
            self::wpis('semrushbot-ocob', 'SemrushBot-OCOB', 'Semrush', 'seo', 'Data collected is used for the ContentShake AI tool reports.', 'https://www.semrush.com/bot/', '', 'yes'),
            self::wpis('semrushbot-swa', 'SemrushBot-SWA', 'Semrush', 'seo', 'Data collected is used for the SEO Writing Assistant tool to check if URL is accessible.', 'https://www.semrush.com/bot/', '', 'yes'),

            // --- monitoring: zwykle własne narzędzia sklepu ------------------
            self::wpis('uptimerobot', 'UptimeRobot', 'Uptime Robot', 'monitoring', 'Checks every few minutes whether the shop is answering.', 'https://uptimerobot.com/', '', 'yes'),

            // --- reszta: nie blokujemy tego, czego nie umiemy nazwać ---------
            self::wpis('facebookexternalhit', 'facebookexternalhit', 'Meta/Facebook', 'other', 'Note that excluding FacebookExternalHit will block incorporating OpenGraph data when sharing in social media, including rich links in Apple\'s Messages app.', 'https://github.com/ai-robots-txt/ai.robots.txt/issues/40#issuecomment-2524591313', '', 'no'),
            self::wpis('google-firebase', 'Google-Firebase', 'Google', 'other', 'Supports Google\'s Firebase AI products.', '', '', 'unclear'),
            self::wpis('quillbot', 'QuillBot', 'Quillbot', 'other', 'Operated by QuillBot as part of their suite of AI product offerings.', 'https://quillbot.com', '', 'unclear'),
            self::wpis('quillbot-com', 'quillbot.com', 'Quillbot', 'other', 'Operated by QuillBot as part of their suite of AI product offerings.', 'https://quillbot.com', '', 'unclear'),
            self::wpis('thinkbot', 'Thinkbot', 'Thinkbot', 'other', 'Collects data for analysis on AI usage and automation.', 'https://www.thinkbot.agency', '', 'no'),
            self::wpis('wpbot', 'wpbot', 'QuantumCloud', 'other', 'wpbot is a used to support the functionality of the AI Chatbot for WordPress plugin.', 'https://forms.gle/ajBaxygz9jSR8p8G9', '', 'unclear'),
        ];
    }

    /**
     * @return array{key:string,token:string,label:string,vendor:string,category:string,description:string,docs_url:string,verify_host:string,respects:string,default_policy:string}
     */
    private static function wpis(
        string $klucz,
        string $token,
        string $wlasciciel,
        string $kategoria,
        string $opis,
        string $dokumentacja = '',
        string $weryfikacja = '',
        string $respektuje = 'unclear',
        string $rekomendacja = Schema::POLICY_ALLOW
    ): array {
        return [
            'key' => $klucz,
            // Token jest zarazem etykietą: nazwa, którą bot się przedstawia, jest
            // tym, czego sprzedawca szuka w logach serwera.
            'token' => $token,
            'label' => $token,
            'vendor' => $wlasciciel,
            'category' => $kategoria,
            'description' => $opis,
            'docs_url' => $dokumentacja,
            'verify_host' => $weryfikacja,
            'respects' => $respektuje,
            'default_policy' => $rekomendacja,
        ];
    }
}
