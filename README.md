# The Co-Founder

An AI-powered Co-Founder application utilizing RAG (Retrieval-Augmented Generation) to assist in startup knowledge, expert assistance, and project management.

## Features
- AI-driven Expert Assistant
- Document Management (RAG integration for PDFs and text)
- Google Cloud Storage support
- Dockerized setup

## Requirements
- PHP 8.x
- Composer
- Node.js & npm (for frontend)
- Google Cloud Platform credentials
- Docker (optional, but recommended)

## Setup

1. **Install PHP Dependencies**:
   ```bash
   composer install
   ```

2. **Environment Variables**:
   Create a `.env` file in the root directory:
   ```env
   GEMINI_API_KEY=your_gemini_api_key
   GOOGLE_STORAGE_BUCKET=your_bucket_name
   CLOUD_STORAGE_BUCKET=your_bucket_name
   ```

3. **Google Cloud Credentials**:
   Ensure you have a Google Service Account key saved as `google-key.json` in the root directory for Google Cloud Storage interactions. Do not commit this file to GitHub!

4. **Frontend Setup**:
   If modifying the frontend code, navigate to the `frontend` directory:
   ```bash
   cd frontend
   npm install
   npm run build
   ```

5. **Run Locally**:
   You can use Docker Compose to spin up the application:
   ```bash
   docker-compose up --build
   ```
   Alternatively, you can use PHP's built-in web server:
   ```bash
   php -S localhost:8000
   ```

## Tech Stack
- **Backend**: PHP, Gemini API, Google Cloud Storage
- **Frontend**: HTML, CSS, JavaScript (Vite build system)
- **Containerization**: Docker, Nginx

## Repository Tags
`php` `ai` `gemini` `rag` `docker` `startup` `assistant`
