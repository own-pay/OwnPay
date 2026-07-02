/**
 * Integration tests for SMS Analysis Flow
 * Tests regex pattern testing and SMS message analysis
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fs from 'fs';
import path from 'path';
import { setupFetchMock, mockSmsData } from '../utils/api-helpers.js';

// Read JavaScript files
const opFetchPath = path.join(__dirname, '../../../public/assets/js/op-fetch.js');
const opFetchCode = fs.readFileSync(opFetchPath, 'utf-8');

describe('SMS Analysis Flow Integration', () => {
  let dom;
  let window;
  let document;
  let fetchMock;

  beforeEach(() => {
    const html = `
      <!DOCTYPE html>
      <html>
      <head>
        <meta name="csrf-token" content="sms-csrf-token">
      </head>
      <body>
        <div id="sms-center">
          <div id="regex-result"></div>
          <div id="analysis-result"></div>
        </div>
      </body>
      </html>
    `;

    dom = new JSDOM(html, {
      url: 'http://localhost',
      runScripts: 'dangerously',
      resources: 'usable',
    });
    
    window = dom.window;
    document = window.document;
    
    fetchMock = setupFetchMock();
    window.fetch = fetchMock.fetch;
    
    window.eval(opFetchCode);
  });

  afterEach(() => {
    dom.window.close();
  });

  describe('Regex Pattern Testing Flow', () => {
    it('should test regex pattern successfully', async () => {
      fetchMock.mockSuccess(mockSmsData.testRegex);
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: '/OTP[:\\s]*(\\d{4,6})/i',
        text: 'Your OTP is 123456',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.matches).toContain('123456');
    });

    it('should handle invalid regex pattern', async () => {
      fetchMock.mockError('Invalid regex pattern', 400);
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: '[',
        text: 'test',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(400);
    });

    it('should handle no matches', async () => {
      fetchMock.mockSuccess({
        pattern: '/\\d{6}/',
        sample_text: 'No numbers here',
        matches: [],
      });
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: '/\\d{6}/',
        text: 'No numbers here',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.matches).toHaveLength(0);
    });

    it('should test complex regex patterns', async () => {
      fetchMock.mockSuccess({
        pattern: '/(?<amount>\\d+(?:\\.\\d{2})?)\\s*(?:BDT|Taka)/i',
        sample_text: 'You received 500.00 BDT',
        matches: ['500.00 BDT'],
        groups: { amount: '500.00' },
      });
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: '/(?<amount>\\d+(?:\\.\\d{2})?)\\s*(?:BDT|Taka)/i',
        text: 'You received 500.00 BDT',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.groups.amount).toBe('500.00');
    });
  });

  describe('SMS Message Analysis Flow', () => {
    it('should analyze single SMS message', async () => {
      fetchMock.mockSuccess(mockSmsData.analyze);
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [{ sender: 'BKASH', body: 'You have received 500 BDT' }],
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.messages).toHaveLength(1);
      expect(result.data.messages[0].parsed.amount).toBe(500);
    });

    it('should analyze multiple SMS messages', async () => {
      fetchMock.mockSuccess({
        messages: [
          {
            sender: 'BKASH',
            body: 'You have received 500 BDT',
            parsed: { amount: 500, currency: 'BDT', type: 'credit' },
          },
          {
            sender: 'NAGAD',
            body: 'You have sent 200 BDT',
            parsed: { amount: 200, currency: 'BDT', type: 'debit' },
          },
        ],
        summary: { total_credit: 500, total_debit: 200 },
      });
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [
          { sender: 'BKASH', body: 'You have received 500 BDT' },
          { sender: 'NAGAD', body: 'You have sent 200 BDT' },
        ],
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.messages).toHaveLength(2);
      expect(result.data.summary.total_credit).toBe(500);
    });

    it('should handle analysis failure', async () => {
      fetchMock.mockError('Failed to analyze messages', 500);
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [{ sender: 'BKASH', body: 'Invalid message' }],
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });

    it('should extract transaction details', async () => {
      fetchMock.mockSuccess({
        messages: [
          {
            sender: 'BKASH',
            body: 'TrxID: ABC123. You have received 1000 BDT from 01712345678.',
            parsed: {
              trx_id: 'ABC123',
              amount: 1000,
              currency: 'BDT',
              type: 'credit',
              sender_account: '01712345678',
            },
          },
        ],
      });
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [{ sender: 'BKASH', body: 'TrxID: ABC123. You have received 1000 BDT from 01712345678.' }],
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.messages[0].parsed.trx_id).toBe('ABC123');
      expect(result.data.messages[0].parsed.sender_account).toBe('01712345678');
    });

    it('should handle empty messages', async () => {
      fetchMock.mockSuccess({ messages: [] });
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [],
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.messages).toHaveLength(0);
    });
  });

  describe('Bulk SMS Processing Flow', () => {
    it('should process bulk messages', async () => {
      fetchMock.mockSuccess({
        processed: 10,
        successful: 8,
        failed: 2,
        results: [
          { status: 'success', message: 'Message 1' },
          { status: 'success', message: 'Message 2' },
          { status: 'failed', message: 'Message 3', error: 'Parse error' },
        ],
      });
      
      const result = await window.opPost('/admin/sms-center/process-bulk', {
        messages: Array.from({ length: 10 }, (_, i) => ({
          sender: 'BKASH',
          body: `Message ${i + 1}`,
        })),
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.processed).toBe(10);
      expect(result.data.successful).toBe(8);
      expect(result.data.failed).toBe(2);
    });

    it('should handle bulk processing failure', async () => {
      fetchMock.mockError('Bulk processing failed', 500);
      
      const result = await window.opPost('/admin/sms-center/process-bulk', {
        messages: [{ sender: 'BKASH', body: 'Test' }],
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });
  });

  describe('Results Export Flow', () => {
    it('should export results as CSV', async () => {
      fetchMock.mockSuccess({
        csv_data: 'sender,amount,currency,type\nBKASH,500,BDT,credit',
        filename: 'sms_analysis_2026-07-02.csv',
      });
      
      const result = await window.opPost('/admin/sms-center/export', {
        format: 'csv',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.csv_data).toContain('sender,amount,currency,type');
    });

    it('should export results as JSON', async () => {
      fetchMock.mockSuccess({
        json_data: { messages: [], summary: {} },
        filename: 'sms_analysis_2026-07-02.json',
      });
      
      const result = await window.opPost('/admin/sms-center/export', {
        format: 'json',
      });
      
      expect(result.ok).toBe(true);
      expect(result.data.json_data).toBeDefined();
    });
  });

  describe('Error Handling', () => {
    it('should handle network errors', async () => {
      fetchMock.mockNetworkError('Connection refused');
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: '/test/',
        text: 'test',
      });
      
      expect(result.ok).toBe(false);
      expect(result.error).toBe('Connection refused');
    });

    it('should handle server errors', async () => {
      fetchMock.mockError('Internal server error', 500);
      
      const result = await window.opPost('/admin/sms-center/analyze', {
        messages: [{ sender: 'BKASH', body: 'test' }],
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(500);
    });

    it('should handle rate limiting', async () => {
      fetchMock.mockError('Too many requests', 429);
      
      const result = await window.opPost('/admin/sms-center/test-regex', {
        pattern: '/test/',
        text: 'test',
      });
      
      expect(result.ok).toBe(false);
      expect(result.status).toBe(429);
    });
  });

  describe('Security', () => {
    it('should include CSRF token in requests', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/sms-center/test-regex', {
        pattern: '/test/',
        text: 'test',
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.headers['X-CSRF-Token']).toBe('sms-csrf-token');
    });

    it('should use same-origin credentials', async () => {
      fetchMock.mockSuccess({});
      
      await window.opPost('/admin/sms-center/analyze', {
        messages: [],
      });
      
      const lastCall = fetchMock.getLastCall();
      expect(lastCall.options.credentials).toBe('same-origin');
    });
  });
});
