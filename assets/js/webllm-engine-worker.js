/**
 * WebLLM engine Web Worker.
 *
 * Runs the WebLLM engine off the main thread. The page connects to it via
 * CreateWebWorkerMLCEngine (see webllm-worker.js). Web Workers reliably support
 * module imports and WebGPU, and — unlike a service worker — need no special
 * scope or serving, so this is a plain same-origin module worker.
 *
 * Tradeoff vs a service worker: the worker (and the loaded model) is tied to its
 * page, so it reloads from cache on navigation rather than staying warm.
 */
import { WebWorkerMLCEngineHandler } from 'https://esm.run/@mlc-ai/web-llm@0.2.84';

const handler = new WebWorkerMLCEngineHandler();

self.onmessage = ( msg ) => {
	handler.onmessage( msg );
};
