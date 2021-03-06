<?php
/**
 * Copyright 2017 OpenCensus Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace OpenCensus\Tests\Unit\Trace;

use OpenCensus\Trace\Span;
use OpenCensus\Trace\SpanContext;
use OpenCensus\Trace\RequestHandler;
use OpenCensus\Trace\Exporter\ExporterInterface;
use OpenCensus\Trace\Sampler\SamplerInterface;
use OpenCensus\Trace\Tracer\NullTracer;
use OpenCensus\Trace\Propagator\HttpHeaderPropagator;
use PHPUnit\Framework\TestCase;

/**
 * @group trace
 */
class RequestHandlerTest extends TestCase
{
    private $exporter;

    private $sampler;

    public function setUp()
    {
        if (extension_loaded('opencensus')) {
            opencensus_trace_clear();
        }
        $this->exporter = $this->prophesize(ExporterInterface::class);
        $this->sampler = $this->prophesize(SamplerInterface::class);
    }

    public function testCanTrackContext()
    {
        $this->sampler->shouldSample()->willReturn(true);

        $rt = new RequestHandler(
            $this->exporter->reveal(),
            $this->sampler->reveal(),
            new HttpHeaderPropagator(),
            [
                'skipReporting' => true
            ]
        );
        $rt->inSpan(['name' => 'inner'], function () {});
        $rt->onExit();
        $spans = $rt->tracer()->spans();
        $this->assertCount(2, $spans);
        foreach ($spans as $span) {
            $this->assertInstanceOf(Span::class, $span);
            $this->assertNotEmpty($span->spanData()->endTime());
        }
        $spanData1 = $spans[0]->spanData();
        $spanData2 = $spans[1]->spanData();
        $this->assertEquals('main', $spanData1->name());
        $this->assertEquals('inner', $spanData2->name());
        $this->assertEquals($spanData1->spanId(), $spanData2->parentSpanId());
    }

    public function testCanParseParentContext()
    {
        $rt = new RequestHandler(
            $this->exporter->reveal(),
            $this->sampler->reveal(),
            new HttpHeaderPropagator(),
            [
                'headers' => [
                    'HTTP_X_CLOUD_TRACE_CONTEXT' => '12345678901234567890123456789012/5555;o=1'
                ],
                'skipReporting' => true
            ]
        );
        $span = $rt->tracer()->spans()[0];
        $this->assertEquals('15b3', $span->spanData()->parentSpanId());
        $context = $rt->tracer()->spanContext();
        $this->assertEquals('12345678901234567890123456789012', $context->traceId());
    }

    public function testForceEnabledContextHeader()
    {
        $rt = new RequestHandler(
            $this->exporter->reveal(),
            $this->sampler->reveal(),
            new HttpHeaderPropagator(),
            [
                'headers' => [
                    'HTTP_X_CLOUD_TRACE_CONTEXT' => '12345678901234567890123456789012;o=1'
                ],
                'skipReporting' => true
            ]
        );
        $tracer = $rt->tracer();

        $this->assertTrue($tracer->enabled());
    }

    public function testForceDisabledContextHeader()
    {
        $rt = new RequestHandler(
            $this->exporter->reveal(),
            $this->sampler->reveal(),
            new HttpHeaderPropagator(),
            [
                'headers' => [
                    'HTTP_X_CLOUD_TRACE_CONTEXT' => '12345678901234567890123456789012;o=0'
                ],
                'skipReporting' => true
            ]
        );
        $tracer = $rt->tracer();

        $this->assertFalse($tracer->enabled());
        $this->assertInstanceOf(NullTracer::class, $tracer);
    }
}
